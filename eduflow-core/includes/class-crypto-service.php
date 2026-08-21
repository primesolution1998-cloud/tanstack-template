<?php
defined( 'ABSPATH' ) || exit;
final class EduFlow_Crypto_Service {
	private static function key(){return hash('sha256',(defined('AUTH_KEY')?AUTH_KEY:'').(defined('SECURE_AUTH_KEY')?SECURE_AUTH_KEY:'').site_url(),true);}
	public static function encrypt($plain){if(''===(string)$plain){return null;}if(!function_exists('openssl_encrypt')){return new WP_Error('crypto_unavailable','OpenSSL is required for Google credential storage.');}$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt((string)$plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'eduflow-google');if(false===$cipher){return new WP_Error('encryption_failed','Credential encryption failed.');}return base64_encode($iv.$tag.$cipher);}
	public static function decrypt($encoded){if(!$encoded){return '';}if(!function_exists('openssl_decrypt')){return new WP_Error('crypto_unavailable','OpenSSL is required for Google credential storage.');}$raw=base64_decode($encoded,true);if(false===$raw||strlen($raw)<29){return new WP_Error('invalid_ciphertext','Stored credential is invalid.');}$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'eduflow-google');return false===$plain?new WP_Error('decryption_failed','Stored credential could not be decrypted.'):$plain;}
}
