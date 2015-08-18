<?php
	
	/**
	 * Plugin Name: WooCommerce Attribute Variation Export
	 * Description: A tool to export attributes for variations
	 * Version: 1.0.0
	 * Author: Creative Little Dots
	 * Author URI: http://creativelittledots.co.uk
	 *
	 * Text Domain: woocommerce-attribute-variation-export
	 * Domain Path: /i18n/languages/
	 *
	 */
	
	class WC_Attribute_Variation_Export {
		
		protected static $_instance = null;
		
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}
		
		public function __construct() {
			
			add_action( 'woocommerce_product_options_attributes', array($this, 'add_export_attribute_variations_button') );
			add_action( 'admin_post_export_attribute_variations', array($this, 'export_attribute_variations') );
			add_action( 'woocommerce_product_option_terms', array($this, 'add_used_for_export_checkbox'), 10, 2 );
			
			add_action( 'wp_ajax_woocommerce_save_attributes', array($this, 'save_attributes'), 9 );
			add_action( 'wp_ajax_nopriv_woocommerce_save_attributes', array($this, 'save_attributes'), 9 );
			
			add_filter( 'woocommerce_attribute_variation_export_attribute', array($this, 'adjust_attribute_data'), 9, 4);
			
			add_action( 'woocommerce_process_product_meta', array($this, 'save_attribute_variation_export'), 40, 2);
			
		}
		
		public function add_export_attribute_variations_button() {
			
			global $thepostid;
			
			?>
			
			<p class="toolbar show_if_variable">
			
				<a class="button export_attribute_variations" href="<?php echo add_query_arg(array('action' => 'export_attribute_variations', 'product_id' => $thepostid, '_wpnonce' => wp_create_nonce('export_attribute_variations')), admin_url('admin-post.php')); ?>"><?php _e( 'Export attribute variations', 'woocommerce' ); ?></a>
				
			</p>
			
			<?php
			
		}
		
		public function export_attribute_variations() {
			
			if(isset($_REQUEST['_wpnonce']) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'export_attribute_variations')) {
			
				if(isset($_REQUEST['product_id'])) {
					
					$product = new WC_Product($_REQUEST['product_id']);
					
					$attributes = $product->get_attributes();
					
					$data = array(
						0 => array(
							'id' => 'post_parent',
							'parent' => 'product_title',
						)
					);
					
					$max_rows = array();
					
					$attribute_indexes = array();
					
					foreach($attributes as $attribute) {
						
						$data[0][$attribute['name']] = 'meta:attribute_' . ($attribute['is_taxonomy'] ? get_taxonomy($attribute['name'])->name : $attribute['name']);
						
						if( $attribute['is_for_attribute_variation_export'] ) {
							
							$max_rows[$attribute['name']] = $attribute['is_taxonomy'] ? count(wc_get_product_terms( $product->id,  $attribute['name'], array( 'fields' => 'all' ))) : count($attribute['values']);
						
							$attribute_indexes[$attribute['name']] = 0;
							
						}
						
					}
					
					$data[0]['regular_price'] = 'regular_price';
					$data[0]['sales_price'] = 'sale_price';
					$data[0]['sku'] = 'sku';
					$data[0]['weight'] = 'weight';
					$data[0]['length'] = 'length';
					$data[0]['width'] = 'width';
					$data[0]['height'] = 'height';
					$data[0]['shipping_class'] = 'tax:product_shipping_class';
					$data[0]['tax_class'] = 'tax_class';
					$data[0]['downloadable'] = 'downloadable';
					$data[0]['virtual'] = 'virtual';
					$data[0]['manage_stock'] = 'manage_stock';
					$data[0]['stock'] = 'stock';
					

					$attributes_increments = array();
					
					foreach(range(1, array_product($max_rows)) as $row) {
						
						$data[$row] = array();
						
						$data[$row]['id'] = $product->id;
						$data[$row]['parent'] = $product->get_title();
						
						foreach($attributes as $attribute) {
							
							$values = $attribute['values'];
							
							if($attribute['is_taxonomy']) {
							
								$terms = wc_get_product_terms( $product->id,  $attribute['name'], array( 'fields' => 'all' ) ) ;
								
								$values = array();
								
								foreach($terms as $term) {
									
									$values[] = $term->name;
								}
								
							}
							
							if( $attribute['is_for_attribute_variation_export'] ) {
							
								// Add value to this row for csv data
							
								$data[$row][$attribute['name']] = $values[$attribute_indexes[$attribute['name']]];
								
								// Calculate weather we should increment this attribute to the next value for the next row
								
								$remainder = $row % array_product( array_slice($max_rows, 0, array_search($attribute['name'], array_keys($max_rows))) );
								
								$attribute_indexes[$attribute['name']] = $remainder == 0  ? $attribute_indexes[$attribute['name']]+1 : $attribute_indexes[$attribute['name']];
								
								// Reset index if index is greater than number of attribute values for the next row
								
								$attribute_indexes[$attribute['name']] = ($attribute_indexes[$attribute['name']]+1) > count($values) ? 0 : $attribute_indexes[$attribute['name']];
								
							} else {
								
								// Add empty value to this row for csv data
							
								$data[$row][$attribute['name']] = '';
								
							}
							
						}
						
						$data[$row]['regular_price'] = '';
						$data[$row]['sales_price'] = '';
						$data[$row]['sku'] = '';
						$data[$row]['weight'] = '';
						$data[$row]['length'] = '';
						$data[$row]['width'] = '';
						$data[$row]['height'] = '';
						$data[$row]['shipping_class'] = '';
						$data[$row]['tax_class'] = '';
						$data[$row]['downloadable'] = '';
						$data[$row]['virtual'] = '';
						$data[$row]['manage_stock'] = 'no';
						$data[$row]['stock'] = '';

						
					}
					
					$filename = "Attribute Variations exported for " . $product->get_title() . ".csv";

					$this->output_csv($filename, $data);
					
				}
				
			}
			
		}
		
		private function output_csv($filename, $data) {
			ob_clean();
			header('Pragma: public');
			header('Expires: 0');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Cache-Control: private', false);
			header('Content-Type: text/csv');
			header('Content-Disposition: attachment;filename=' . $filename);    
			$output = fopen("php://output", "w");
			foreach ($data as $row) {
			  fputcsv($output, $row); // here you can change delimiter/enclosure
			}
			fclose($output);
			ob_flush();
		}
		
		public function add_used_for_export_checkbox( $attribute_taxonomy, $i ) {
			
			global $thepostid;
			
			$attributes = maybe_unserialize( get_post_meta( $thepostid, '_product_attributes', true ) );
			
			if($attributes) {
				$attribute_keys = array_keys( $attributes );
				$attribute = $attributes[ $attribute_keys[ $i ] ];
			}

			?>
			
			<hr />
			
			<label><input type="checkbox" class="checkbox attribute_variation_export" <?php checked( $attribute['is_for_attribute_variation_export'], 1 ); ?> name="attribute_variation_export[<?php echo $i; ?>]" value="1" /> <?php _e( 'Used for attribute variation export', 'woocommerce' ); ?></label>
			
			<?php
			
		}
		
		public function save_attributes() {
	
			check_ajax_referer( 'save-attributes', 'security' );
	
			if ( ! current_user_can( 'edit_products' ) ) {
				die(-1);
			}
	
			// Get post data
			parse_str( $_POST['data'], $data );
			$post_id = absint( $_POST['post_id'] );
	
			// Save Attributes
			$attributes = array();
	
			if ( isset( $data['attribute_names'] ) ) {
	
				$attribute_names  = array_map( 'stripslashes', $data['attribute_names'] );
				$attribute_values = isset( $data['attribute_values'] ) ? $data['attribute_values'] : array();
	
				if ( isset( $data['attribute_visibility'] ) ) {
					$attribute_visibility = $data['attribute_visibility'];
				}
	
				if ( isset( $data['attribute_variation'] ) ) {
					$attribute_variation = $data['attribute_variation'];
				}
	
				$attribute_is_taxonomy = $data['attribute_is_taxonomy'];
				$attribute_position    = $data['attribute_position'];
				$attribute_names_count = sizeof( $attribute_names );
	
				for ( $i = 0; $i < $attribute_names_count; $i++ ) {
					if ( ! $attribute_names[ $i ] ) {
						continue;
					}
	
					$is_visible   = isset( $attribute_visibility[ $i ] ) ? 1 : 0;
					$is_variation = isset( $attribute_variation[ $i ] ) ? 1 : 0;
					$is_taxonomy  = $attribute_is_taxonomy[ $i ] ? 1 : 0;
	
					if ( $is_taxonomy ) {
	
						if ( isset( $attribute_values[ $i ] ) ) {
	
							// Select based attributes - Format values (posted values are slugs)
							if ( is_array( $attribute_values[ $i ] ) ) {
								$values = array_map( 'sanitize_title', $attribute_values[ $i ] );
	
							// Text based attributes - Posted values are term names - don't change to slugs
							} else {
								$values = array_map( 'stripslashes', array_map( 'strip_tags', explode( WC_DELIMITER, $attribute_values[ $i ] ) ) );
							}
	
							// Remove empty items in the array
							$values = array_filter( $values, 'strlen' );
	
						} else {
							$values = array();
						}
	
						// Update post terms
						if ( taxonomy_exists( $attribute_names[ $i ] ) ) {
							wp_set_object_terms( $post_id, $values, $attribute_names[ $i ] );
						}
	
						if ( $values ) {
							// Add attribute to array, but don't set values
							$attributes[ sanitize_title( $attribute_names[ $i ] ) ] = array(
								'name' 			=> wc_clean( $attribute_names[ $i ] ),
								'value' 		=> '',
								'position' 		=> $attribute_position[ $i ],
								'is_visible' 	=> $is_visible,
								'is_variation' 	=> $is_variation,
								'is_taxonomy' 	=> $is_taxonomy
							);
						}
	
					} elseif ( isset( $attribute_values[ $i ] ) ) {
	
						// Text based, separate by pipe
						$values = implode( ' ' . WC_DELIMITER . ' ', array_map( 'trim', array_map( 'wp_kses_post', array_map( 'stripslashes', explode( WC_DELIMITER, $attribute_values[ $i ] ) ) ) ) );
	
						// Custom attribute - Add attribute to array and set the values
						$attributes[ sanitize_title( $attribute_names[ $i ] ) ] = array(
							'name' 			=> wc_clean( $attribute_names[ $i ] ),
							'value' 		=> $values,
							'position' 		=> $attribute_position[ $i ],
							'is_visible' 	=> $is_visible,
							'is_variation' 	=> $is_variation,
							'is_taxonomy' 	=> $is_taxonomy
						);
					}
					
					$attributes[ sanitize_title( $attribute_names[ $i ] ) ] = apply_filters('woocommerce_attribute_variation_export_attribute', $attributes[ sanitize_title( $attribute_names[ $i ] ) ], $i, $data, $post_id);
	
				 }
			}
	
			if ( ! function_exists( 'attributes_cmp' ) ) {
				function attributes_cmp( $a, $b ) {
					if ( $a['position'] == $b['position'] ) {
						return 0;
					}
	
					return ( $a['position'] < $b['position'] ) ? -1 : 1;
				}
			}
			
			uasort( $attributes, 'attributes_cmp' );
	
			update_post_meta( $post_id, '_product_attributes', $attributes );
	
			die();
		}
		
		public function adjust_attribute_data($attribute, $i, $data, $post_id) {
			
			if ( isset( $data['attribute_variation_export'] ) ) {
				$attribute_variation_export = $data['attribute_variation_export'];
				$is_for_attribute_variation_export   = isset( $attribute_variation_export[ $i ] ) ? 1 : 0;
				$attribute['is_for_attribute_variation_export'] = $is_for_attribute_variation_export;
			}
			
			return $attribute;
			
		}
		
		public function save_attribute_variation_export($post_id, $posted) {
	
			$product = wc_get_product($post_id);
			
			if($product->is_type('variable')) {
				
				$attributes = $product->get_attributes();
				
				$i = 0;
				
				foreach($attributes as &$attribute) {
					
					if(isset($_POST['attribute_variation_export'][$i])) {
						
						$is_for_attribute_variation_export = $_POST['attribute_variation_export'][$i] ? 1 : 0;
						
						$attribute['is_for_attribute_variation_export'] = $is_for_attribute_variation_export;
						
					}
					
					$i++;
					
				}
				
				update_post_meta($post_id, '_product_attributes', $attributes);
			
			}
			
		}
		
	}
	
	function WC_Attribute_Variation_Export() {
		return WC_Attribute_Variation_Export::instance();
	}
	
	$GLOBALS['WC_Attribute_Variation_Export'] = WC_Attribute_Variation_Export();

?>