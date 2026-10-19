<?php
/** Large-table, cursor-based database dumper. @package Nobash\Backup */
namespace Nobash\Backup\Database;
use Nobash\Backup\Compat\DatabaseAdapter;
use Nobash\Backup\Compat\Multisite;
use Nobash\Backup\Filesystem\Exclusions;
defined( 'ABSPATH' ) || exit;
class Dumper {
	const ROWS_PER_CHUNK = 500;
	const MAX_STATEMENT_BYTES = 2097152;
	/** @var DatabaseAdapter */ private $db;
	/** @var TableCursor */ private $cursor;
	/** @var array<string,array<string,string>> */ private $type_cache = array();
	/** @var array<string,resource> */ private $handles = array();
	public function __construct() { $this->db = new DatabaseAdapter(); $this->cursor = new TableCursor( $this->db ); }
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes direct handles opened for resumable, checkpointed export I/O (fseek/ftruncate/append); WP_Filesystem has no streaming/seek primitives for this.
	public function __destruct() { foreach ( $this->handles as $handle ) { if ( is_resource( $handle ) ) { @fclose( $handle ); } } }
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	public function tables( $scope = 'site' ) {
		$all=$this->db->list_base_tables(); $excluded=Exclusions::default_tables(); $scope=Multisite::normalize_scope($scope); $out=array();
		foreach($all as $table){ if(!Multisite::table_in_scope($table,$scope)){continue;} global $wpdb; $prefix=('network'===$scope)?Multisite::base_prefix():$wpdb->prefix; $short=(0===strpos($table,$prefix))?substr($table,strlen($prefix)):$table; $short_unblog=preg_match('/^\d+_(.+)$/',$short,$m)?$m[1]:$short; if(in_array($short,$excluded,true)||in_array($short_unblog,$excluded,true)||in_array($table,$excluded,true)){continue;} $out[]=$table; }
		return $out;
	}
	/** Rewind an unfinished dump to its last durable cursor before appending. @return int */
	public function prepare_file( $file, $committed_offset = 0 ) {
		$file=(string)$file;
		if ( '' === trim( $file ) ) { throw new \RuntimeException( esc_html__( 'The database export checkpoint path is missing.', 'nobash-backup' ) ); }
		$committed_offset=max(0,(int)$committed_offset); $this->close_handle($file);
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Resumable checkpointed export file requires a direct handle for fstat/ftruncate/fseek; WP_Filesystem has no streaming/seek primitives.
		$fh=fopen($file,is_file($file)?'c+b':'w+b'); if(!$fh){throw new \RuntimeException(esc_html__('Could not open the database export checkpoint.','nobash-backup'));}
		$stat=fstat($fh); $size=is_array($stat)&&isset($stat['size'])?(int)$stat['size']:0;
		if($committed_offset>$size){fclose($fh);throw new \RuntimeException(esc_html__('The database export checkpoint is incomplete. Restart this unfinished backup safely.','nobash-backup'));}
		if($size>$committed_offset&&!ftruncate($fh,$committed_offset)){fclose($fh);throw new \RuntimeException(esc_html__('Could not rewind the database export checkpoint.','nobash-backup'));}
		if(0!==fseek($fh,$committed_offset,SEEK_SET)){fclose($fh);throw new \RuntimeException(esc_html__('Could not seek the database export checkpoint.','nobash-backup'));}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->handles[$file]=$fh; return $committed_offset;
	}
	/** Flush once per durable job slice instead of reopening/flocking on every SQL batch. @return int */
	public function checkpoint( $file ) {
		$file=(string)$file;
		if ( '' === trim( $file ) ) { throw new \RuntimeException( esc_html__( 'The database export checkpoint path is missing.', 'nobash-backup' ) ); }
		$fh=$this->handle($file); if(!fflush($fh)){throw new \RuntimeException(esc_html__('Could not flush the database export checkpoint.','nobash-backup'));}
		if(function_exists('fsync')){@fsync($fh);} $pos=ftell($fh); if(false===$pos){$stat=fstat($fh);$pos=is_array($stat)&&isset($stat['size'])?(int)$stat['size']:0;} return (int)$pos;
	}
	public function preamble(){ return (string) $this->db->dump_preamble(); }
	public function epilogue(){ return (string) $this->db->dump_epilogue(); }
	public function create_block($table,$schema_source=''){ return (string) $this->db->format_create_block($table,$schema_source); }
	public function write_preamble($file){ $this->append($file,$this->preamble()); }
	public function write_epilogue($file){ $this->append($file,$this->epilogue()); }
	public function write_create($table,$file,$schema_source=''){ $this->append($file,$this->create_block($table,$schema_source)); }
	/** @return array<int,array<string,string>> */ public function objects(){ return $this->db->list_database_objects(); }
	/** @return void */ public function write_object(array $object,$file){ $this->append($file,$this->db->format_object_block($object)); }
	public function plan($table){ return $this->cursor->plan($table); }
	/** @return array{cursor:array<string,mixed>,rows:int,bytes:int,done:bool,warnings:array<int,string>,sql:string} */
	public function render_chunk($table,array $state,$limit=self::ROWS_PER_CHUNK,$max_bytes=self::MAX_STATEMENT_BYTES,$output_table=''){
		global $wpdb;
		$limit=max(1,min(5000,(int)$limit));
		$max_bytes=max(262144,min(8388608,(int)$max_bytes));
		if('keyset'===($state['strategy']??'')&&!empty($state['upper_bound_empty'])){return array('cursor'=>$state,'rows'=>0,'bytes'=>0,'done'=>true,'warnings'=>isset($state['warning'])?array($state['warning']):array(),'sql'=>'');}
		$output_table=''===(string)$output_table?(string)$table:(string)$output_table;
		$q_output=$this->db->quote_id($output_table);$where_args=array();$where='';$order='';$order_args=array();
		if('keyset'===($state['strategy']??'')){
			$columns=(array)($state['columns']??array());$values=(array)($state['values']??array());$upper_values=(array)($state['upper_values']??array());$clauses=array();
			$predicate=$this->cursor->predicate($columns,$values);if(''!==$predicate['sql']){$clauses[]=$predicate['sql'];$where_args=array_merge($where_args,$predicate['args']);}
			$upper=$this->cursor->upper_predicate($columns,$upper_values);if(''!==$upper['sql']){$clauses[]=$upper['sql'];$where_args=array_merge($where_args,$upper['args']);}
			if(!empty($clauses)){$where=' WHERE '.implode(' AND ',$clauses);}
			if(!empty($columns)){$order=' ORDER BY '.implode(',',array_fill(0,count($columns),'%i')).' ASC';$order_args=$columns;}
		}
		if('offset'===($state['strategy']??'')){$sql='SELECT * FROM %i LIMIT %d OFFSET %d';$args=array($table,$limit,(int)($state['offset']??0));}else{$sql='SELECT * FROM %i'.$where.$order.' LIMIT %d';$args=array_merge(array($table),$where_args,$order_args,array($limit));}
		$types=$this->column_types($table);$written=0;$returned=0;$bytes=0;$last=null;$tuples=array();$tuple_args=array();$columns=array();$prefix='';$statement_bytes=0;$limited_by_bytes=false;
		$stream=$this->stream_rows($sql,$args);
		foreach($stream as $row){
			++$returned;
			if(empty($columns)){$columns=array_keys($row);$quoted_columns=array_map(array($this->db,'quote_id'),$columns);$prefix='INSERT INTO '.$q_output.' ('.implode(',',$quoted_columns).') VALUES ';$statement_bytes=strlen($prefix)+2;}
			$tuple=$this->value_tuple_template($row,$columns,$types);
			$next_bytes=$statement_bytes+(int)$tuple['estimated_bytes']+(empty($tuples)?0:1);
			if($written>0&&$next_bytes>$max_bytes){$limited_by_bytes=true;break;}
			$tuples[]=(string)$tuple['template'];
			if(!empty($tuple['args'])){foreach((array)$tuple['args'] as $arg){$tuple_args[]=$arg;}}
			$statement_bytes=$next_bytes;$written++;$last=$row;
		}
		unset($stream);
		if(0===$returned){return array('cursor'=>$state,'rows'=>0,'bytes'=>0,'done'=>true,'warnings'=>isset($state['warning'])?array($state['warning']):array(),'sql'=>'');}
		$statement='';
		if(!empty($tuples)){
			$body=implode(',',$tuples);
			if(!empty($tuple_args)){
				/* Preparing the complete VALUES body once per SQL statement preserves
				 * WordPress/driver escaping but avoids running wpdb's placeholder parser
				 * once for every row. Multi-gigabyte databases can contain millions of
				 * rows, so this removes a major PHP CPU hot path on shared hosting. */
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $body is a locally built template of %s placeholders only, one per column value; no user input is concatenated.
				$body=$wpdb->prepare($body,$tuple_args);
				if(!is_string($body)){throw new \RuntimeException(esc_html__('Could not encode a database batch for export.','nobash-backup'));}
			}
			$statement=$prefix.$body.";\n";
		}
		$bytes=strlen($statement);
		if('keyset'===($state['strategy']??'')&&is_array($last)){$state['values']=array();foreach((array)$state['columns'] as $column){$state['values'][]=$last[$column];}}
		if('offset'===($state['strategy']??'')){$state['offset']=(int)($state['offset']??0)+$written;}$state['rows']=(int)($state['rows']??0)+$written;
		$done=!$limited_by_bytes&&$returned<$limit;
		return array('cursor'=>$state,'rows'=>$written,'bytes'=>$bytes,'done'=>$done,'warnings'=>isset($state['warning'])?array($state['warning']):array(),'sql'=>$statement);
	}
	/** @return array{cursor:array<string,mixed>,rows:int,bytes:int,done:bool,warnings:array<int,string>} */
	public function dump_chunk($table,$file,array $state,$limit=self::ROWS_PER_CHUNK,$max_bytes=self::MAX_STATEMENT_BYTES,$output_table=''){
		$result=$this->render_chunk($table,$state,$limit,$max_bytes,$output_table);
		if(''!==$result['sql']){$this->append($file,$result['sql']);}
		unset($result['sql']);
		return $result;
	}


	/**
	 * Yield rows without buffering an entire MySQL result set in PHP memory.
	 *
	 * WordPress database drop-ins and SQLite keep the normal wpdb fallback. On the
	 * common mysqli path MYSQLI_USE_RESULT keeps memory proportional to one row plus
	 * the bounded SQL statement being assembled, instead of limit × row-size.
	 *
	 * @return \Generator<int,array<string,mixed>>
	 */
	private function stream_rows( $sql, array $args ) {
		global $wpdb;
		$prepared = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder count is generated locally; all identifiers/values are passed as arguments.
		if ( ! is_string( $prepared ) || '' === $prepared ) { throw new \RuntimeException( esc_html__( 'Could not prepare a database export batch.', 'nobash-backup' ) ); }
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli && defined( 'MYSQLI_USE_RESULT' ) ) {
			$result = $wpdb->dbh->query( $prepared, MYSQLI_USE_RESULT ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Unbuffered streaming is unavailable through wpdb.
			if ( false === $result ) {
				$message = isset( $wpdb->dbh->error ) ? (string) $wpdb->dbh->error : '';
				throw new \RuntimeException( sprintf( /* translators: %s database error */ esc_html__( 'Could not stream a database export batch: %s', 'nobash-backup' ), esc_html( $message ) ) );
			}
			try {
				while ( $row = $result->fetch_assoc() ) { yield $row; }
			} finally {
				$result->free();
			}
			return;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic placeholder count is generated internally and all identifiers/values are supplied as prepare arguments.
		if ( ! is_array( $rows ) ) { return; }
		foreach ( $rows as $row ) { yield $row; }
	}

	/** @return array<string,string> */
	private function column_types($table){$key=(string)$table;if(isset($this->type_cache[$key])){return $this->type_cache[$key];}$types=array();foreach($this->db->get_columns($table) as $meta){$types[(string)$meta['Field']]=strtolower((string)$meta['Type']);}$this->type_cache[$key]=$types;return $types;}
	/** Build one tuple template and conservatively estimate its escaped byte size.
	 * @return array{template:string,args:array<int,string>,estimated_bytes:int}
	 */
	private function value_tuple_template( array $row, array $columns, array $types ) {
		$values = array();
		$args   = array();
		$estimated = 2; // Parentheses.
		foreach ( $columns as $col ) {
			$val = array_key_exists( $col, $row ) ? $row[ $col ] : null;
			if ( null === $val ) {
				$values[] = 'NULL';
				$estimated += 4;
				continue;
			}
			$type = $types[ $col ] ?? '';
			if ( false !== strpos( $type, 'binary' ) || false !== strpos( $type, 'blob' ) ) {
				$encoded = "X'" . bin2hex( (string) $val ) . "'";
				$values[] = $encoded;
				$estimated += strlen( $encoded );
			} else {
				$values[] = '%s';
				$string = (string) $val;
				$args[] = $string;
				/* SQL escaping can at most roughly double the bytes for the control/
				 * quote characters handled by MySQL/SQLite. Add surrounding quotes too,
				 * so statement-size limits remain conservative before prepare(). */
				$estimated += ( 2 * strlen( $string ) ) + 2;
			}
		}
		if ( count( $values ) > 1 ) { $estimated += count( $values ) - 1; }
		return array( 'template' => '(' . implode( ',', $values ) . ')', 'args' => $args, 'estimated_bytes' => $estimated );
	}
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Append-only writes to a resumable, checkpointed export file held open across many calls; WP_Filesystem has no persistent append-handle primitive.
	/** @return resource */ private function handle($file){$file=(string)$file;if(''===trim($file)){throw new \RuntimeException(esc_html__('The database export checkpoint path is missing.','nobash-backup'));}if(isset($this->handles[$file])&&is_resource($this->handles[$file])){return $this->handles[$file];}$fh=fopen($file,'ab');if(!$fh){throw new \RuntimeException(esc_html__('Could not write the database export checkpoint.','nobash-backup'));}$this->handles[$file]=$fh;return $fh;}
	private function close_handle($file){$file=(string)$file;if(isset($this->handles[$file])&&is_resource($this->handles[$file])){@fclose($this->handles[$file]);}unset($this->handles[$file]);}
	private function append($file,$data){$fh=$this->handle($file);$length=strlen($data);$offset=0;while($offset<$length){$n=fwrite($fh,$offset>0?substr($data,$offset):$data);if(false===$n||0===$n){throw new \RuntimeException(esc_html__('Could not write the database export checkpoint.','nobash-backup'));}$offset+=$n;}}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
}
