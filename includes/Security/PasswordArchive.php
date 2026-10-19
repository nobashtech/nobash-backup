<?php
/**
 * Password-protected .nbh container with resumable authenticated encryption.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Security;

use Nobash\Backup\Archive\NativeArchiveReader;
use Nobash\Backup\Archive\NativeArchiveWriter;
use Nobash\Backup\Filesystem\ChunkFingerprint;

defined( 'ABSPATH' ) || exit;

final class PasswordArchive {
	const MAGIC = "NBHP1\n";
	const END_MAGIC = "NBHPEND2\n";
	const VERSION = 2;
	const CHUNK_SIZE = 8388608;
	const MAX_HEADER = 65536;
	const MAX_TRAILER = 65536;
	const CHECKSUM_SCHEME = 'encrypted-chunk-chain-v1';

	public static function is_password_protected( $path ) {
		if ( ! is_readable( $path ) ) return false;
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Peeking at a fixed-size magic-byte header; archives can be multi-GB so reading the whole file via WP_Filesystem would be wasteful.
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) return false;
		$magic = (string) fread( $fh, strlen( self::MAGIC ) );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return self::MAGIC === $magic;
	}

	public static function engine() {
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && function_exists( 'sodium_crypto_pwhash' ) ) return 'sodium';
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) return 'openssl';
		return 'none';
	}

	public static function available() { return 'none' !== self::engine(); }

	/** @return array<string,mixed> */
	public static function status() {
		return array(
			'enabled'         => true,
			'available'       => self::available(),
			'engine'          => self::engine(),
			'mode'            => 'password-protected',
			'portable'        => true,
			'authenticated'   => true,
			'confidentiality' => true,
		);
	}

	public static function looks_like_archive( $bytes ) {
		return 0 === strpos( (string) $bytes, self::MAGIC ) || 0 === strpos( (string) $bytes, NativeArchiveWriter::MAGIC );
	}

	/** @return array<string,mixed> */
	public static function init_encrypt( $source, $dest, $password ) {
		if ( ! self::available() ) throw new \RuntimeException( esc_html__( 'Password protection requires libsodium or OpenSSL AES-256-GCM.', 'nobash-backup' ) );
		if ( ! is_readable( $source ) ) throw new \RuntimeException( esc_html__( 'Backup archive is not readable for encryption.', 'nobash-backup' ) );
		if ( strlen( (string) $password ) < 10 ) throw new \RuntimeException( esc_html__( 'Use a password with at least 10 characters.', 'nobash-backup' ) );
		$salt = random_bytes( 16 );
		$engine = self::engine();
		$fingerprint = NativeArchiveReader::is_native( $source ) ? ( new NativeArchiveReader( $source ) )->fingerprint() : (string) hash_file( 'sha256', $source );
		$scheme = NativeArchiveReader::is_native( $source ) ? ChunkFingerprint::ARCHIVE_SCHEME : 'sha256';
		$header = array(
			'version' => self::VERSION,
			'engine' => $engine,
			'kdf' => 'sodium' === $engine ? 'argon2id' : 'pbkdf2-sha256',
			'salt' => base64_encode( $salt ),
			'chunk_size' => self::CHUNK_SIZE,
			'original_size' => (int) filesize( $source ),
			'original_fingerprint' => $fingerprint,
			'original_checksum_scheme' => $scheme,
			'created_at' => gmdate( 'c' ),
		);
		$json = wp_json_encode( $header, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_HEADER ) throw new \RuntimeException( esc_html__( 'Could not create encrypted archive header.', 'nobash-backup' ) );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Part of the resumable authenticated-encryption protocol: the returned dest_offset is used by encrypt_slice() to fseek()/ftruncate() and resume writing across separate requests; WP_Filesystem has no seek/fsync primitives needed for crash-safe resumability.
		$fh = fopen( $dest, 'w+b' );
		if ( ! $fh ) throw new \RuntimeException( esc_html__( 'Could not create encrypted archive.', 'nobash-backup' ) );
		self::write_all( $fh, self::MAGIC . pack( 'N', strlen( $json ) ) . $json );
		self::sync( $fh );
		$offset = ftell( $fh );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'source_offset' => 0,
			'dest_offset' => (int) $offset,
			'chunk_index' => 0,
			'cipher_chain' => ChunkFingerprint::seed( 'encrypted-container' ),
			'header' => $header,
			'done' => false,
		);
	}

	/** @return array<string,mixed> */
	public static function encrypt_slice( $source, $dest, $password, array $state, $time_budget ) {
		if ( ! empty( $state['done'] ) ) return $state;
		$header = (array) $state['header'];
		$key = self::derive_key( (string) $password, base64_decode( (string) $header['salt'], true ), (string) $header['engine'] );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked, resumable, seekable archive I/O across multiple requests; WP_Filesystem has no fseek()/ftruncate()/streaming primitives and would require loading multi-GB archives into memory.
		$in = fopen( $source, 'rb' ); $out = fopen( $dest, 'c+b' );
		if ( ! $in || ! $out ) { if($in)fclose($in); if($out)fclose($out); throw new \RuntimeException( esc_html__( 'Could not continue encrypted archive creation.', 'nobash-backup' ) ); }
		$source_offset = (int) $state['source_offset'];
		$dest_offset = (int) $state['dest_offset'];
		$idx = (int) $state['chunk_index'];
		$chain = (string) ( $state['cipher_chain'] ?? ChunkFingerprint::seed( 'encrypted-container' ) );
		if ( 0 !== fseek( $in, $source_offset ) || 0 !== fseek( $out, $dest_offset ) || ! ftruncate( $out, $dest_offset ) ) { fclose($in); fclose($out); throw new \RuntimeException( esc_html__( 'Encrypted archive checkpoint is invalid.', 'nobash-backup' ) ); }
		$started = microtime(true); $total = (int) $header['original_size'];
		while ( $source_offset < $total && microtime(true)-$started < max(0.5,(float)$time_budget) ) {
			$chunk_start = $source_offset;
			$plain = fread( $in, min( self::CHUNK_SIZE, $total - $source_offset ) );
			if ( false === $plain ) { fclose($in); fclose($out); throw new \RuntimeException( esc_html__( 'Could not read the archive for encryption.', 'nobash-backup' ) ); }
			if ( '' === $plain ) break;
			$aad = hash( 'sha256', wp_json_encode( $header ) . '|' . $idx, true );
			if ( 'sodium' === $header['engine'] ) {
				$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
				$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $aad, $nonce, $key );
				$record = pack('N',strlen($plain)) . chr(strlen($nonce)) . $nonce . pack('N',strlen($cipher)) . $cipher;
			} else {
				$nonce = random_bytes( 12 ); $tag='';
				$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
				if ( false === $cipher ) { fclose($in); fclose($out); throw new \RuntimeException( esc_html__( 'Could not encrypt archive chunk.', 'nobash-backup' ) ); }
				$payload = $tag . $cipher;
				$record = pack('N',strlen($plain)) . chr(strlen($nonce)) . $nonce . pack('N',strlen($payload)) . $payload;
			}
			self::write_all($out,$record); fflush($out);
			$chain = ChunkFingerprint::advance( $chain, hash( 'sha256', $record ), $chunk_start, strlen( $record ), $idx );
			$source_offset += strlen($plain); $dest_offset += strlen($record); $idx++;
		}
		if ( $source_offset >= $total ) {
			$fingerprint = ChunkFingerprint::finalize( $chain, $source_offset, $idx, 'encrypted-container' );
			$trailer = array( 'version' => self::VERSION, 'chunk_count' => $idx, 'cipher_chain' => $chain, 'container_fingerprint' => $fingerprint, 'checksum_scheme' => self::CHECKSUM_SCHEME );
			$json = wp_json_encode( $trailer, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) || strlen( $json ) > self::MAX_TRAILER ) { fclose($in); fclose($out); throw new \RuntimeException( esc_html__( 'Could not finalize the encrypted archive.', 'nobash-backup' ) ); }
			$tail = self::END_MAGIC . pack( 'N', strlen( $json ) ) . $json;
			self::write_all( $out, $tail ); self::sync( $out );
			$dest_offset += strlen( $tail );
			$state['container_fingerprint'] = $fingerprint;
			$state['checksum_scheme'] = self::CHECKSUM_SCHEME;
			$state['done'] = true;
		}
		self::sync( $out ); fclose($in); fclose($out);
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$state['source_offset']=$source_offset; $state['dest_offset']=$dest_offset; $state['chunk_index']=$idx; $state['cipher_chain']=$chain; $state['header']=$header;
		return $state;
	}

	/** @return array<string,mixed> */
	public static function inspect( $path ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Reads only the small fixed-format header of the encrypted container; the file itself can be a multi-GB archive so WP_Filesystem's whole-file read is not viable.
		$fh=fopen($path,'rb'); if(!$fh)throw new \RuntimeException(esc_html__('Could not open encrypted archive.','nobash-backup'));
		$magic=(string)fread($fh,strlen(self::MAGIC)); if(self::MAGIC!==$magic){fclose($fh);throw new \RuntimeException(esc_html__('Not a password-protected .nbh archive.','nobash-backup'));}
		$len=unpack('Nlen',self::read_exact($fh,4)); $n=(int)$len['len']; if($n<2||$n>self::MAX_HEADER){fclose($fh);throw new \RuntimeException(esc_html__('Encrypted archive header is invalid.','nobash-backup'));}
		$json=self::read_exact($fh,$n); $header=json_decode($json,true); $offset=ftell($fh); fclose($fh);
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$version = is_array( $header ) ? (int) ( $header['version'] ?? 0 ) : 0;
		if(!is_array($header)||empty($header['salt'])||empty($header['engine'])||self::VERSION!==$version)throw new \RuntimeException(esc_html__('Encrypted archive header is invalid.','nobash-backup'));
		return array('header'=>$header,'source_offset'=>(int)$offset);
	}

	/** Read the declared resumable container fingerprint without hashing gigabytes again. */
	public static function fingerprint( $path ) {
		$info = self::inspect( $path );
		$header = (array) $info['header'];
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Walks a potentially multi-GB archive record-by-record with fseek() to avoid reading ciphertext into memory; WP_Filesystem has no seek primitive.
		$fh = fopen( $path, 'rb' ); if ( ! $fh ) throw new \RuntimeException( esc_html__( 'Could not open encrypted archive.', 'nobash-backup' ) );
		if ( 0 !== fseek( $fh, (int) $info['source_offset'] ) ) { fclose($fh); throw new \RuntimeException( esc_html__( 'Encrypted archive checkpoint is invalid.', 'nobash-backup' ) ); }
		$plain = 0; $expected = (int) $header['original_size'];
		while ( $plain < $expected ) {
			$plain_len = (int) unpack( 'Nlen', self::read_exact( $fh, 4 ) )['len'];
			$nonce_len = ord( self::read_exact( $fh, 1 ) );
			if ( $plain_len < 1 || $plain_len > self::CHUNK_SIZE || $nonce_len < 8 || $nonce_len > 32 ) { fclose($fh); throw new \RuntimeException( esc_html__( 'Encrypted archive record is invalid.', 'nobash-backup' ) ); }
			if ( 0 !== fseek( $fh, $nonce_len, SEEK_CUR ) ) { fclose($fh); throw new \RuntimeException( esc_html__( 'Encrypted archive is truncated.', 'nobash-backup' ) ); }
			$cipher_len = (int) unpack( 'Nlen', self::read_exact( $fh, 4 ) )['len'];
			if ( $cipher_len < 16 || $cipher_len > self::CHUNK_SIZE + 64 || 0 !== fseek( $fh, $cipher_len, SEEK_CUR ) ) { fclose($fh); throw new \RuntimeException( esc_html__( 'Encrypted archive record is invalid.', 'nobash-backup' ) ); }
			$plain += $plain_len;
		}
		if ( $plain !== $expected ) { fclose($fh); throw new \RuntimeException( esc_html__( 'Encrypted archive size does not match its header.', 'nobash-backup' ) ); }
		$trailer = self::read_trailer( $fh ); fclose($fh);
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fingerprint = strtolower( (string) ( $trailer['container_fingerprint'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) throw new \RuntimeException( esc_html__( 'Encrypted archive trailer is invalid.', 'nobash-backup' ) );
		return $fingerprint;
	}

	/** @return array<string,mixed> */
	public static function init_decrypt( $source, $dest ) {
		$info=self::inspect($source);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Creates an empty workspace file that decrypt_slice() reopens with fseek()/ftruncate() to resume writing across requests; part of the resumable authenticated-encryption protocol.
		$fh=fopen($dest,'w+b'); if(!$fh)throw new \RuntimeException(esc_html__('Could not create decrypted archive workspace.','nobash-backup')); fclose($fh);
		return array('source_offset'=>(int)$info['source_offset'],'dest_offset'=>0,'chunk_index'=>0,'cipher_chain'=>ChunkFingerprint::seed('encrypted-container'),'header'=>$info['header'],'done'=>false);
	}

	/** @return array<string,mixed> */
	public static function decrypt_slice( $source, $dest, $password, array $state, $time_budget ) {
		if ( ! empty( $state['done'] ) ) return $state;
		$h=(array)$state['header']; $key=self::derive_key((string)$password,base64_decode((string)$h['salt'],true),(string)$h['engine']);
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked, resumable, seekable archive I/O across multiple requests; WP_Filesystem has no fseek()/ftruncate()/streaming primitives and would require loading multi-GB archives into memory.
		$in=fopen($source,'rb');$out=fopen($dest,'c+b'); if(!$in||!$out){if($in)fclose($in);if($out)fclose($out);throw new \RuntimeException(esc_html__('Could not continue encrypted archive decryption.','nobash-backup'));}
		$so=(int)$state['source_offset'];$do=(int)$state['dest_offset'];$idx=(int)$state['chunk_index'];$chain=(string)($state['cipher_chain']??ChunkFingerprint::seed('encrypted-container'));
		if(0!==fseek($in,$so)||0!==fseek($out,$do)||!ftruncate($out,$do)){fclose($in);fclose($out);throw new \RuntimeException(esc_html__('Encrypted archive checkpoint is invalid.','nobash-backup'));}
		$started=microtime(true);$expected=(int)$h['original_size'];
		while($do<$expected && microtime(true)-$started<max(.5,(float)$time_budget)){
			$chunk_start=$do;$record='';
			$lenBytes=self::read_exact($in,4);$record.=$lenBytes;
			$plainLen=(int)unpack('Nlen',$lenBytes)['len'];$nonceByte=self::read_exact($in,1);$record.=$nonceByte;$nonceLen=ord($nonceByte);
			if($plainLen<1||$plainLen>self::CHUNK_SIZE||$nonceLen<8||$nonceLen>32){fclose($in);fclose($out);throw new \RuntimeException(esc_html__('Encrypted archive record is invalid.','nobash-backup'));}
			$nonce=self::read_exact($in,$nonceLen);$record.=$nonce;$cipherLenBytes=self::read_exact($in,4);$record.=$cipherLenBytes;$cipherLen=(int)unpack('Nlen',$cipherLenBytes)['len'];
			if($cipherLen<16||$cipherLen>self::CHUNK_SIZE+64){fclose($in);fclose($out);throw new \RuntimeException(esc_html__('Encrypted archive record is invalid.','nobash-backup'));}
			$payload=self::read_exact($in,$cipherLen);$record.=$payload;$aad=hash('sha256',wp_json_encode($h).'|'.$idx,true);
			if('sodium'===$h['engine']){$plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($payload,$aad,$nonce,$key);}else{$plain=openssl_decrypt(substr($payload,16),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,substr($payload,0,16),$aad);}
			if(false===$plain||strlen($plain)!==$plainLen){fclose($in);fclose($out);throw new \RuntimeException(esc_html__('Incorrect password or corrupted encrypted archive.','nobash-backup'));}
			self::write_all($out,$plain);fflush($out);
			$chain=ChunkFingerprint::advance($chain,hash('sha256',$record),$chunk_start,strlen($record),$idx);
			$so=(int)ftell($in);$do+=strlen($plain);$idx++;
		}
		if($do>=$expected){
			$trailer=self::read_trailer($in);$expected_chain=(string)($trailer['cipher_chain']??'');$fingerprint=ChunkFingerprint::finalize($chain,$do,$idx,'encrypted-container');
			if((int)($trailer['chunk_count']??-1)!==$idx||!hash_equals(strtolower($expected_chain),strtolower($chain))||!hash_equals(strtolower((string)($trailer['container_fingerprint']??'')),strtolower($fingerprint))){fclose($in);fclose($out);throw new \RuntimeException(esc_html__('Encrypted archive integrity verification failed.','nobash-backup'));}
			$scheme=(string)($h['original_checksum_scheme']??'sha256');$actual=ChunkFingerprint::ARCHIVE_SCHEME===$scheme&&NativeArchiveReader::is_native($dest)?(new NativeArchiveReader($dest))->fingerprint():(string)hash_file('sha256',$dest);
			$expected_original=(string)($h['original_fingerprint']??'');
			if(!hash_equals(strtolower($expected_original),strtolower($actual))){fclose($in);fclose($out);throw new \RuntimeException(esc_html__('Incorrect password or corrupted encrypted archive.','nobash-backup'));}
			$state['container_fingerprint']=$fingerprint;$state['checksum_scheme']=self::CHECKSUM_SCHEME;$so=(int)ftell($in);
			$state['done']=true;
		}
		self::sync($out);fclose($in);fclose($out);
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$state['source_offset']=$so;$state['dest_offset']=$do;$state['chunk_index']=$idx;$state['cipher_chain']=$chain;$state['header']=$h;
		return $state;
	}

	/** @return array<string,mixed> */
	private static function read_trailer( $fh ) {
		$magic = self::read_exact( $fh, strlen( self::END_MAGIC ) );
		if ( self::END_MAGIC !== $magic ) throw new \RuntimeException( esc_html__( 'Encrypted archive trailer is missing.', 'nobash-backup' ) );
		$length = (int) unpack( 'Nlen', self::read_exact( $fh, 4 ) )['len'];
		if ( $length < 2 || $length > self::MAX_TRAILER ) throw new \RuntimeException( esc_html__( 'Encrypted archive trailer is invalid.', 'nobash-backup' ) );
		$data = json_decode( self::read_exact( $fh, $length ), true );
		if ( ! is_array( $data ) ) throw new \RuntimeException( esc_html__( 'Encrypted archive trailer is invalid.', 'nobash-backup' ) );
		return $data;
	}

	private static function derive_key($password,$salt,$engine){
		if(!is_string($salt)||strlen($salt)!==16)throw new \RuntimeException(esc_html__('Encrypted archive salt is invalid.','nobash-backup'));
		if('sodium'===$engine&&function_exists('sodium_crypto_pwhash'))return sodium_crypto_pwhash(32,$password,$salt,3,32 * 1024 * 1024,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		if('openssl'===$engine)return hash_pbkdf2('sha256',$password,$salt,600000,32,true);
		throw new \RuntimeException(esc_html__('This server cannot process the encrypted archive.','nobash-backup'));
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads a caller-specified exact byte count from an already-open seekable handle shared by the chunked/resumable archive I/O methods above; not a WP_Filesystem-compatible whole-file operation.
	private static function read_exact($fh,$length){$data='';while(strlen($data)<$length){$c=fread($fh,$length-strlen($data));if(false===$c||''===$c)throw new \RuntimeException(esc_html__('Encrypted archive is truncated.','nobash-backup'));$data.=$c;}return $data;}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to an already-open seekable handle shared by the chunked/resumable archive I/O methods above; not a WP_Filesystem-compatible whole-file operation.
	private static function write_all($fh,$bytes){$off=0;$len=strlen($bytes);while($off<$len){$n=fwrite($fh,substr($bytes,$off));if(false===$n||0===$n)throw new \RuntimeException(esc_html__('Could not write encrypted archive.','nobash-backup'));$off+=$n;}}
	private static function sync($fh){fflush($fh);if(function_exists('fsync'))@fsync($fh);}
}
