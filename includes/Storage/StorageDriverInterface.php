<?php
/** Provider-neutral remote storage contract. @package Nobash\Backup */
namespace Nobash\Backup\Storage;
defined( 'ABSPATH' ) || exit;
interface StorageDriverInterface {
	public function id();
	public function label();
	public function capabilities();
	public function test_connection( array $config = array() );
	public function available_capacity( array $config = array() );
	public function put( $local_path, array $args = array() );
	public function initiate_multipart( $remote_id, $size, array $args = array() );
	public function upload_part( $upload_id, $part_number, $bytes, array $args = array() );
	public function list_uploaded_parts( $upload_id, array $args = array() );
	public function complete_multipart( $upload_id, array $parts, array $args = array() );
	public function abort_multipart( $upload_id, array $args = array() );
	public function get( $remote_id, $dest );
	public function get_range( $remote_id, $offset, $length, array $args = array() );
	public function metadata( $remote_id, array $args = array() );
	public function delete( $remote_id );
	public function list_objects( array $args = array() );
}
