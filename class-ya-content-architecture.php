<?php

/**
 * Contains main architure class
 * 
 * @version 0.1
 * @author Saurabh Shukla <saurabh@yapapaya.com>
 * @author Yapapaya <dev@yapapaya.com>
 * @license GPL-2.0
 * @license https://opensource.org/licenses/GPL-2.0 GNU Public License
 * @package YA_Content_Architecture
 */
if ( !class_exists( 'YA_Content_Architecture' ) ) {

	/**
	 * Builds the content architecture for WordPress plugins
	 * 
	 * @author Saurabh Shukla <saurabh@yapapaya.com>
	 */
	class YA_Content_Architecture {

		/**
		 * Architecture configuration
		 * 
		 * @var array 
		 */
		private $architecture = array();

		/**
		 * Custom table names
		 * 
		 * @var array
		 */
		private $tables = array();

		/**
		 * Custom meta data table names
		 * 
		 * @var array 
		 */
		private $meta_tables = array();

		/**
		 * Table preix
		 * 
		 * @var string
		 */
		private $prefix = '';

		/**
		 * Database architecture version
		 *  
		 * @var string
		 */
		private $version = '';

		/**
		 * Path to directory where schema information is stored
		 * 
		 * @var string
		 */
		private $schema_path = '';

		/**
		 * Constructor
		 * 
		 * @param string $prefix table prefix
		 * @param type $schema_path Path to schema directory
		 * @param string $db_version The version number
		 * @return type
		 */
		public function __construct( $prefix, $schema_path, $db_version = '0.0.1' ) {

			if ( empty( $prefix ) || empty( $schema_path ) ) {
				return;
			}

			$this->prefix = $prefix;

			$this->version = $db_version;

			$this->schema_path = trailingslashit( $schema_path );

			$this->architecture = include_once $this->schema_path . 'config.php';

			$this->initialise_table_names();
		}
		
		/**
		 * Initialises custom table names
		 * 
		 * @global object $wpdb
		 */
		private function initialise_table_names() {

			global $wpdb;

			foreach ( $this->architecture['custom'] as $name => $params ) {

				$this->tables[$name] = $wpdb->prefix . $this->prefix . '_' . $this->prettify( $name );

				if ( empty( $params ) ) {
					continue;
				}

				if ( !isset( $params['has_meta'] ) ) {
					continue;
				}

				if ( $params['has_meta'] === true ) {
					$this->meta_tables[$name] = $wpdb->prefix . $this->prefix . '_' . $this->prettify( $name ) . 'meta';
				}
			}
		}

		/*
		 * ================================
		 * Installation methods
		 * ================================
		 * Use on plugin installation/ activation
		 */

		/**
		 * Creates custom tables
		 * 
		 * @return array result strings from dbDelta()
		 */
		public function install() {

			$update_result_tables = $this->install_tables();

			$update_result_meta_tables = $this->install_meta_tables();

			$update_results = $update_result_tables + $update_result_meta_tables;

			$this->update_db_version();

			return $update_results;
		}

		/**
		 * Installs custom content tables
		 * 
		 * @global object $wpdb
		 * @return array
		 */
		private function install_tables() {

			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$for_update = array();

			foreach ( $this->tables as $file_name => $table_name ) {

				$sql = file_get_contents($this->schema_path . 'custom/' . $file_name . '.php');

				$sql = sprintf( $sql, $table_name );

				$sql .= $charset_collate . ';';

				$for_update[] = dbDelta( $sql );
			}

			return $for_update;
		}

		/**
		 * Installs custom meta tables for custom tables
		 * 
		 * @global object $wpdb
		 * @return array
		 */
		private function install_meta_tables() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$for_update = array();

			foreach ( $this->meta_tables as $index => $table_name ) {

				$sql = file_get_contents($this->schema_path . 'custom-meta/custom-meta.php');
				
				$sql = sprintf( $sql, $table_name );

				$sql .= $charset_collate . ';';

				$for_update[] = dbDelta( $sql );
			}

			return $for_update;
		}

		/**
		 * Updates database version in options table
		 */
		private function update_db_version() {

			add_option( $this->prefix . '_db_version', $this->version );
		}
		
		/** 
		 * Returns all table names (for uninstallation)
		 * 
		 * @return array
		 */
		public function get_table_names(){
			return $this->tables + $this->meta_tables;
		}

		/*
		 * ================================
		 * Initialisation methods
		 * ================================
		 */

		/**
		 * Initialises all content on WP init
		 */
		public function init() {

			// intialise cpts and taxonomies
			add_action( 'init', array( $this, 'init_wp_types' ) );

			// initialise meta tables
			add_action( 'init', array( $this, 'hook_meta_tables' ), 0 );
			add_action( 'switch_blog', array( $this, 'hook_meta_tables' ), 0 );
		}

		/**
		 * Initialises cpts & taxonomies
		 */
		public function init_wp_types() {
			$this->register_post_types();
			$this->register_taxonomies();
		}

		/**
		 * Registers cpts 
		 * 
		 */
		public function register_post_types() {

			foreach ( $this->architecture['post_type'] as $post_type ) {

				// include the schema
				$arguments = include_once $this->schema_path  . 'post_type/' . $post_type . '.php';

				// register post_type
				register_post_type( $post_type, $arguments );
			}
		}
		
		/**
		 * Registers taxonomies
		 * 
		 */
		public function register_taxonomies() {

			foreach ( $this->architecture['taxonomy'] as $taxonomy=>$object ) {
				
				if(empty($object)){
					$object = array();
				}

				// include the schema
				$arguments = include_once $this->schema_path . 'taxonomy/' . $taxonomy . '.php';

				// register taxonomy
				register_taxonomy( $taxonomy, $object, $arguments );
			}
		}

		/**
		 * Initialises custom meta tables for Metadata API
		 * 
		 * @global object $wpdb
		 */
		public function hook_meta_tables() {

			global $wpdb;

			foreach ( $this->meta_tables as $name=>$table ) {
				$wpdb->$name = $this->prettify( $name ) . 'meta';

				$wpdb->tables[] = $this->prettify( $name ) . 'meta';
			}
		}

		/*
		 * ================================
		 * Helper methods
		 * ================================
		 */

		/**
		 * Replaces hyphens with underscores in a string for use in table names
		 * 
		 * @param string $string_with_hyphens
		 * @return string
		 */
		private function prettify( $string_with_hyphens ) {

			$string_with__s = str_replace( '-', '_', $string_with_hyphens );

			return $string_with__s;
		}

	}

}
