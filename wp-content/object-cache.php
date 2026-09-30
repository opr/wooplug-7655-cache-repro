<?php
/**
 * Minimal object-cache drop-in for the WOOPLUG-7655 reproduction harness.
 *
 * Only HPOS's orders_data group persists between requests. APCu is local to
 * each PHP container, deliberately modelling a missed cross-worker cache
 * invalidation. This is a fault-injection fixture, not a production cache.
 */

class WP_Object_Cache {
	/** @var int */
	public $cache_hits = 0;

	/** @var int */
	public $cache_misses = 0;

	/** @var array<string, array<string, mixed>> */
	private $cache = array();

	/** @var array<string, bool> */
	private $global_groups = array();

	/** @var array<string, bool> */
	private $non_persistent_groups = array();

	/** @var string */
	private $blog_prefix = '1:';

	/** @var string */
	private $site_prefix = '';

	public function __construct() {
		global $blog_id, $table_prefix;

		$this->blog_prefix = ( (int) $blog_id ?: 1 ) . ':';
		$this->site_prefix = (string) $table_prefix;
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		$found = false;
		$this->get( $key, $group, false, $found );
		return $found ? false : $this->set( $key, $data, $group, $expire );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$found = false;
		$this->get( $key, $group, false, $found );
		return $found ? $this->set( $key, $data, $group, $expire ) : false;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$group = $this->normalize_group( $group );
		$key   = (string) $key;
		$value = is_object( $data ) ? clone $data : $data;

		$this->cache[ $group ][ $key ] = $value;

		if ( $this->is_persistent_group( $group ) ) {
			return apcu_store( $this->persistent_key( $key, $group ), $value, max( 0, (int) $expire ) );
		}

		return true;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$group = $this->normalize_group( $group );
		$key   = (string) $key;

		if ( ! $force && array_key_exists( $key, $this->cache[ $group ] ?? array() ) ) {
			$found = true;
			++$this->cache_hits;
			$value = $this->cache[ $group ][ $key ];
			return is_object( $value ) ? clone $value : $value;
		}

		if ( $this->is_persistent_group( $group ) ) {
			$success = false;
			$value   = apcu_fetch( $this->persistent_key( $key, $group ), $success );
			if ( $success ) {
				$this->cache[ $group ][ $key ] = $value;
				$found                         = true;
				++$this->cache_hits;
				return is_object( $value ) ? clone $value : $value;
			}
		}

		$found = false;
		++$this->cache_misses;
		return false;
	}

	public function delete( $key, $group = 'default', $deprecated = false ) {
		$group  = $this->normalize_group( $group );
		$key    = (string) $key;
		$exists = array_key_exists( $key, $this->cache[ $group ] ?? array() );
		unset( $this->cache[ $group ][ $key ] );

		if ( $this->is_persistent_group( $group ) ) {
			return apcu_delete( $this->persistent_key( $key, $group ) ) || $exists;
		}

		return $exists;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found || ! is_numeric( $value ) ) {
			return false;
		}
		$value += (int) $offset;
		$this->set( $key, $value, $group );
		return $value;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found || ! is_numeric( $value ) ) {
			return false;
		}
		$value = max( 0, $value - (int) $offset );
		$this->set( $key, $value, $group );
		return $value;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}
		return $values;
	}

	public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
		$results = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->set( $key, $value, $group, $expire );
		}
		return $results;
	}

	public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
		$results = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $results;
	}

	public function delete_multiple( array $keys, $group = 'default' ) {
		$results = array();
		foreach ( $keys as $key ) {
			$results[ $key ] = $this->delete( $key, $group );
		}
		return $results;
	}

	public function flush() {
		$this->cache = array();
		return apcu_clear_cache();
	}

	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	public function flush_group( $group ) {
		$group = $this->normalize_group( $group );
		unset( $this->cache[ $group ] );

		if ( $this->is_persistent_group( $group ) ) {
			$version_key = $this->group_version_key( $group );
			$version     = (int) apcu_fetch( $version_key );
			return apcu_store( $version_key, $version + 1 );
		}

		return true;
	}

	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $group ) {
			$this->global_groups[ (string) $group ] = true;
		}
	}

	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $group ) {
			$this->non_persistent_groups[ (string) $group ] = true;
		}
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = (int) $blog_id . ':';
	}

	public function reset() {
		return $this->flush_runtime();
	}

	public function close() {
		return true;
	}

	private function normalize_group( $group ) {
		return '' === (string) $group ? 'default' : (string) $group;
	}

	private function is_persistent_group( $group ) {
		return 'orders_data' === $group && empty( $this->non_persistent_groups[ $group ] ) && function_exists( 'apcu_fetch' );
	}

	private function persistent_key( $key, $group ) {
		$prefix  = isset( $this->global_groups[ $group ] ) ? $this->site_prefix : $this->site_prefix . $this->blog_prefix;
		$version = (int) apcu_fetch( $this->group_version_key( $group ) );
		$version = $version ?: 1;
		return 'wooplug-7655:' . md5( $prefix . '|' . $group . '|' . $version . '|' . $key );
	}

	private function group_version_key( $group ) {
		return 'wooplug-7655:group-version:' . md5( $this->site_prefix . '|' . $group );
	}
}

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}

function wp_cache_delete( $key, $group = '', $deprecated = false ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group, $deprecated );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add_multiple( $data, $group, $expire );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
}

function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush();
}

function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	return $GLOBALS['wp_object_cache']->flush_group( $group );
}

function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
}

function wp_cache_reset() {
	_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
	return $GLOBALS['wp_object_cache']->reset();
}

function wp_cache_close() {
	return $GLOBALS['wp_object_cache']->close();
}

function wp_cache_supports( $feature ) {
	return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true );
}
