<?php
/** Versioned, failure-isolated add-on registry. @package Nobash\Backup */
namespace Nobash\Backup\Addon;
use Nobash\Backup\Container\Container;
use Nobash\Backup\Storage\LocalStorage;
use Nobash\Backup\Storage\LocalStorageDriver;
use Nobash\Backup\Storage\StorageDriverInterface;
defined( 'ABSPATH' ) || exit;
class Registry {
	const SDK_VERSION = '1.0.0';
	const CORE_VERSION = '1.0.0.1';
	private $container;
	private $states;
	private $booted=false;
	private $storage_drivers=array();
	private $job_types=array();
	private $job_result_keys=array();
	private $features=array();
	private $ui_extensions=array();
	private $rest_routes=array();
	private $migrations=array();
	private $manifests=array();
	private $errors=array();
	public function __construct( ?Container $container=null, ?AddonStateRepository $states=null ){
		$this->container=$container?:new Container();
		$this->states=$states?:((class_exists(AddonStateRepository::class))?new AddonStateRepository():null);
	}
	public function boot(){
		if($this->booted)return;
		$this->booted=true;
		$this->register_storage(new LocalStorageDriver(new LocalStorage()),'core');
		try{
			do_action('nobash_backup_register_addons',$this);
		} catch(\Throwable $e){
			$this->errors['hook_registration']=$this->safe_error($e);
		}
		try{
			do_action('nobash_backup_register_storage',$this);
		} catch(\Throwable $e){
			$this->errors['hook_storage']=$this->safe_error($e);
		}
		$this->run_migrations();
		add_action('rest_api_init',array($this,'register_rest_routes'),20);
	}
	public function register_addon( AddonInterface $addon ){
		$id='unknown-'.substr(hash('sha256',get_class($addon)),0,12);
		try {
			$manifest=$this->validate_manifest($addon->manifest());
			$id=$manifest['id'];
			if(isset($this->manifests[$id]))throw new AddonException('Add-on already registered: '.$id);
			$this->validate_compatibility($manifest);
			$this->manifests[$id]=$manifest;
			$addon->register(new AddonContext($this,$id,$this->container));
			if($this->states){
				$state=$this->states->get($id);
				$this->states->mark_active($id,$manifest,(string)($state['migration_version']??''));
			}
		}
		catch(\Throwable $e){
			$this->errors[$id]=$this->safe_error($e);
			if($this->states){
				$this->states->mark_error($id,$this->errors[$id]);
			}
			do_action('nobash_backup_addon_failed',$id,$this->errors[$id]);
		}
	}
	private function validate_manifest($m){
		if(!is_array($m))throw new AddonException('Add-on manifest must be an array.');
		$m=wp_parse_args($m,array('id'=>'','name'=>'','version'=>'','api_version'=>'','requires_core'=>'1.0.0','requires_php'=>'7.4','requires_wp'=>'6.2','dependencies'=>array()));
		$m['id']=sanitize_key($m['id']);
		if(!$m['id']||!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/',$m['id']))throw new AddonException('Invalid add-on id.');
		foreach(array('name','version','api_version') as $k){
			if(''===(string)$m[$k])throw new AddonException('Missing add-on manifest field: '.esc_html($k));
		}
		$m['dependencies']=is_array($m['dependencies'])?$m['dependencies']:array();
		return $m;
	}
	private function validate_compatibility(array $m){
		global $wp_version;
		if(version_compare(PHP_VERSION,$m['requires_php'],'<'))throw new AddonException('Requires PHP '.esc_html($m['requires_php']).' or newer.');
		if(isset($wp_version)&&version_compare($wp_version,$m['requires_wp'],'<'))throw new AddonException('Requires WordPress '.esc_html($m['requires_wp']).' or newer.');
		if(version_compare(self::CORE_VERSION,$m['requires_core'],'<'))throw new AddonException('Requires Nobash Backup '.esc_html($m['requires_core']).' or newer.');
		if(version_compare(self::SDK_VERSION,$m['api_version'],'<'))throw new AddonException('Requires unsupported Add-on API '.esc_html($m['api_version']).'.');
		foreach($m['dependencies'] as $dep=>$constraint){
			$dep=sanitize_key($dep);
			if(!isset($this->manifests[$dep]))throw new AddonException('Missing dependency: '.esc_html($dep));
			if($constraint&&version_compare($this->manifests[$dep]['version'],(string)$constraint,'<'))throw new AddonException('Dependency '.esc_html($dep).' is too old.');
		}
	}
	public function register_service($addon_id,$id,callable $factory){
		if($this->container->has($id))throw new AddonException('Service already registered: '.esc_html($id));
		$this->container->singleton($id,$this->guard($addon_id,$factory));
	}
	public function register_storage(StorageDriverInterface $driver,$addon_id='core'){
		$id=sanitize_key((string)$driver->id());
		if(!$id)throw new AddonException('Storage driver id is required.');
		if(isset($this->storage_drivers[$id]))throw new AddonException('Storage driver already registered: '.esc_html($id));
		$this->storage_drivers[$id]=$driver;
	}
	public function get_storage($id){
		return $this->storage_drivers[sanitize_key($id)]??null;
	}
	public function storage_drivers(){
		return $this->storage_drivers;
	}
	public function register_job_type($type,$handler,$addon_id='external',array $public_result_keys=array()){
		$type=sanitize_key($type);
		if(!$type||!is_callable($handler))throw new AddonException('Invalid job type registration.');
		if(isset($this->job_types[$type]))throw new AddonException('Job type already registered: '.esc_html($type));
		$this->job_types[$type]=$this->guard_job($addon_id,$handler);
		$this->job_result_keys[$type]=array_values(array_filter(array_map('sanitize_key',$public_result_keys)));
	}
	public function get_job_type($type){
		return $this->job_types[sanitize_key($type)]??null;
	}
	public function job_types(){
		return (array)apply_filters('nobash_backup_job_types',$this->job_types);
	}
	public function public_result_keys($type){
		return $this->job_result_keys[sanitize_key($type)]??array();
	}
	public function register_feature($feature,array $meta=array(),$addon_id='external'){
		$feature=sanitize_key($feature);
		if(!$feature)throw new AddonException('Feature id is required.');
		$this->features[$feature]=array('addon'=>$addon_id,'meta'=>$meta);
	}
	public function features(){
		return $this->features;
	}
	public function has_feature($feature){
		return isset($this->features[sanitize_key($feature)]);
	}
	public function register_ui_extension($slot,callable $renderer,$addon_id='external'){
		$slot=sanitize_key($slot);
		$this->ui_extensions[$slot][]=array('addon'=>$addon_id,'renderer'=>$this->guard($addon_id,$renderer));
	}
	public function render_ui_extensions($slot,array $context=array()){
		foreach($this->ui_extensions[sanitize_key($slot)]??array() as $e){
			try{
				call_user_func($e['renderer'],$context);
			} catch(\Throwable $x){
				/* isolated and already guarded */
			}
		}
	}
	public function register_rest_route($namespace,$route,array $args,$addon_id='external'){
		$namespace=trim((string)$namespace,'/');
		if(0!==strpos($namespace,'nobash-backup-addon-'))throw new AddonException('Add-on REST namespace must start with nobash-backup-addon-.');
		if(empty($args['permission_callback'])||!is_callable($args['permission_callback']))throw new AddonException('Add-on REST routes require a permission callback.');
		if(isset($args['callback'])&&is_callable($args['callback']))$args['callback']=$this->guard($addon_id,$args['callback']);
		$this->rest_routes[]=array($namespace,$route,$args);
	}
	public function register_rest_routes(){
		foreach($this->rest_routes as $r){
			register_rest_route($r[0],$r[1],$r[2]);
		}
	}
	public function register_migration(MigrationInterface $migration,$addon_id='external'){
		$this->migrations[$addon_id][]=$migration;
	}
	private function run_migrations(){
		foreach($this->migrations as $addon_id=>$items){
			$state=$this->states?$this->states->get($addon_id):array();
			$current=(string)($state['migration_version']??'');
			try{
				usort($items,function($a,$b){
					return version_compare($a->version(),$b->version());
				});
				foreach($items as $m){
					if(version_compare($current,$m->version(),'<')){
						$m->up();
						$current=$m->version();
					}
				}
				if($this->states&&isset($this->manifests[$addon_id]))$this->states->mark_active($addon_id,$this->manifests[$addon_id],$current);
			} catch(\Throwable $e){
				$this->errors[$addon_id]=$this->safe_error($e);
				if($this->states)$this->states->mark_error($addon_id,$this->errors[$addon_id]);
			}
		}
	}
	private function guard_job($addon_id,callable $cb){
		return function()use($addon_id,$cb){
			try{
				return call_user_func_array($cb,func_get_args());
			} catch(\Throwable $e){
				$msg=$this->safe_error($e);
				$this->errors[$addon_id]=$msg;
				if('core'!==$addon_id&&$this->states)$this->states->mark_error($addon_id,$msg);
				throw $e;
			}
		};
	}
	private function guard($addon_id,callable $cb){
		return function()use($addon_id,$cb){
			try{
				return call_user_func_array($cb,func_get_args());
			} catch(\Throwable $e){
				$msg=$this->safe_error($e);
				$this->errors[$addon_id]=$msg;
				if('core'!==$addon_id&&$this->states)$this->states->mark_error($addon_id,$msg);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous Throwable passed to the exception constructor, not raw output.
				throw new AddonException('Add-on operation failed safely: '.esc_html($addon_id),0,$e);
			}
		};
	}
	private function safe_error(\Throwable $e){
		$m=preg_replace('#(?:[A-Za-z]:)?[/\\][^\s:]+#','[path]',(string)$e->getMessage());
		if(function_exists('sanitize_text_field')){
			$m=sanitize_text_field($m);
		} else{
			$m=wp_strip_all_tags($m, true);
		}
		return substr($m,0,500);
	}
	public function deactivate_addon($id){
		$id=sanitize_key($id);
		if(!$id)return;
		unset($this->manifests[$id],$this->errors[$id]);
		if($this->states)$this->states->save($id,array('status'=>'inactive','last_error'=>''));
		do_action('nobash_backup_addon_deactivated',$id);
	}
	public function manifests(){
		return $this->manifests;
	}
	public function errors(){
		return $this->errors;
	}
	public function states(){
		return $this->states?$this->states->all():array();
	}
}
