<?php
/**
 * Metabox Functions
 *
 * @package     EDD
 * @subpackage  Admin/Downloads
 * @copyright   Copyright (c) 2013, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/** All Downloads *****************************************************************/

/**
 * Register all the meta boxes for the Download custom post type
 *
 * @since 1.0
 * @return void
 */
function edd_add_download_meta_box() {

	$post_types = apply_filters( 'edd_download_metabox_post_types' , array( 'download' ) );

	foreach ( $post_types as $post_type ) {

		/** Product Prices **/
		add_meta_box( 'edd_product_prices', sprintf( __( '%1$s Prices', 'edd' ), edd_get_label_singular(), edd_get_label_plural() ),  'edd_render_download_meta_box', $post_type, 'normal', 'high' );

		/** Product Files (and bundled products) **/
		add_meta_box( 'edd_product_files', sprintf( __( '%1$s Files', 'edd' ), edd_get_label_singular(), edd_get_label_plural() ),  'edd_render_files_meta_box', $post_type, 'normal', 'high' );

		/** Product Settings **/
		add_meta_box( 'edd_product_settings', sprintf( __( '%1$s Settings', 'edd' ), edd_get_label_singular(), edd_get_label_plural() ),  'edd_render_settings_meta_box', $post_type, 'side', 'high' );
		
		/** Product Notes */
		add_meta_box( 'edd_product_notes', sprintf( __( '%1$s Notes', 'edd' ), edd_get_label_singular(), edd_get_label_plural() ), 'edd_render_product_notes_meta_box', $post_type, 'normal', 'high' );

		if ( current_user_can( 'view_shop_reports' ) || current_user_can( 'edit_product', get_the_ID() ) ) {
			/** Product Stats */
			add_meta_box( 'edd_product_stats', sprintf( __( '%1$s Stats', 'edd' ), edd_get_label_singular(), edd_get_label_plural() ), 'edd_render_stats_meta_box', $post_type, 'side', 'high' );
		}
	}
}
add_action( 'add_meta_boxes', 'edd_add_download_meta_box' );

/**
 * Sabe post meta when the save_post action is called
 *
 * @since 1.0
 * @param int $post_id Download (Post) ID
 * @global array $post All the data of the the current post
 * @return void
 */
function edd_download_meta_box_save( $post_id) {
	global $post, $edd_options;

	if ( ! isset( $_POST['edd_download_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['edd_download_meta_box_nonce'], basename( __FILE__ ) ) )
		return $post_id;

	if ( ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX') && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) )
		return $post_id;

	if ( isset( $post->post_type ) && $post->post_type == 'revision' )
		return $post_id;

	if ( ! current_user_can( 'edit_product', $post_id ) )
		return $post_id;

	// The default fields that get saved
	$fields = apply_filters( 'edd_metabox_fields_save', array(
			'_edd_product_type',
			'edd_price',
			'_variable_pricing',
			'_edd_price_options_mode',
			'edd_variable_prices',
			'edd_download_files',
			'_edd_purchase_text',
			'_edd_purchase_style',
			'_edd_purchase_color',
			'_edd_bundled_products',
			'_edd_hide_purchase_link',
			'_edd_button_behavior',
			'edd_product_notes'
		)
	);

	if ( current_user_can( 'manage_shop_settings' ) ) {
		$fields[] = '_edd_download_limit';
	}

	if ( edd_use_skus() ) {
		$fields[] = 'edd_sku';
	}

	foreach ( $fields as $field ) {
        if ( ! empty( $_POST[ $field ] ) ) {
			$new = apply_filters( 'edd_metabox_save_' . $field, $_POST[ $field ] );
			update_post_meta( $post_id, $field, $new );
		} else {
			delete_post_meta( $post_id, $field );
		}
	}

	if( edd_has_variable_prices( $post_id ) ) {
		$lowest = edd_get_lowest_price_option( $post_id );
		update_post_meta( $post_id, 'edd_price', $lowest );
	}
}
add_action( 'save_post', 'edd_download_meta_box_save' );

/**
 * Sanitize the price before it is saved
 *
 * This is mostly for ensuring commas aren't saved in the price
 *
 * @since 1.3.2
 * @param string $price Price before sanitization
 * @return float $price Sanitized price
 */
function edd_sanitize_price_save( $price ) {
	return edd_sanitize_amount( $price );
}
add_filter( 'edd_metabox_save_edd_price', 'edd_sanitize_price_save' );

/**
 * Sanitize the variable prices
 *
 * Ensures prices are correctly mapped to an array starting with an index of 0
 *
 * @since 1.4.2
 * @param array $prices Variable prices
 * @return array $prices Array of the remapped variable prices
 */
function edd_sanitize_variable_prices_save( $prices ) {

	global $post;

	// Make sure all prices are rekeyed starting at 0
	return array_values( $prices );
}
add_filter( 'edd_metabox_save_edd_variable_prices', 'edd_sanitize_variable_prices_save' );

/**
 * Sanitize bundled products on save
 *
 * Ensures a user doesn't try and include a product's ID in the products bundled with that product
 *
 * @since       1.6
 *
 * @param array $products
 * @return array
 */
function edd_sanitize_bundled_products_save( $products = array() ) {

	global $post;

	$self = array_search( $post->ID, $products );

	if( $self !== false )
		unset( $products[ $self ] );

	return array_values( array_unique( $products ) );
}
add_filter( 'edd_metabox_save__edd_bundled_products', 'edd_sanitize_bundled_products_save' );


/**
 * Sanitize the file downloads
 *
 * Ensures files are correctly mapped to an array starting with an index of 0
 *
 * @since 1.5.1
 * @param array $files Array of all the file downloads
 * @return array $files Array of the remapped file downloads
 */
function edd_sanitize_files_save( $files ) {

	// Make sure all files are rekeyed starting at 0
	return array_values( $files );
}
add_filter( 'edd_metabox_save_edd_download_files', 'edd_sanitize_files_save' );

/**
 * Don't save blank rows.
 *
 * When saving, check the price and file table for blank rows.
 * If the name of the price or file is empty, that row should not
 * be saved.
 *
 * @since 1.2.2
 * @param array $new Array of all the meta values
 * @return array $new New meta value with empty keys removed
 */
function edd_metabox_save_check_blank_rows( $new ) {
	foreach ( $new as $key => $value ) {
		if ( empty( $value['name'] ) && empty( $value['amount'] ) && empty( $value['file'] ) )
			unset( $new[ $key ] );
	}

	return $new;
}
add_filter( 'edd_metabox_save_edd_variable_prices', 'edd_metabox_save_check_blank_rows' );
add_filter( 'edd_metabox_save_edd_download_files', 'edd_metabox_save_check_blank_rows' );


/** Download Configuration *****************************************************************/

/**
 * Download Metabox
 *
 * Extensions (as well as the core plugin) can add items to the main download
 * configuration metabox via the `edd_meta_box_fields` action.
 *
 * @since 1.0
 * @return void
 */
function edd_render_download_meta_box() {
	global $post, $edd_options;

	/*
	 * Output the price fields
	 * @since 1.9
	 */
	do_action( 'edd_meta_box_price_fields', $post->ID );

	/*
	 * Output the price fields
	 *
	 * Left for backwards compatibility
	 *
	 */
	do_action( 'edd_meta_box_fields', $post->ID );

	wp_nonce_field( basename( __FILE__ ), 'edd_download_meta_box_nonce' );
}

/**
 * Download Files Metabox
 *
 * @since 1.9
 * @return void
 */
function edd_render_files_meta_box() {
	global $post, $edd_options;

	/*
	 * Output the files fields
	 * @since 1.9
	 */
	do_action( 'edd_meta_box_files_fields', $post->ID );
}

/**
 * Download Settings Metabox
 *
 * @since 1.9
 * @return void
 */
function edd_render_settings_meta_box() {
	global $post, $edd_options;

	/*
	 * Output the files fields
	 * @since 1.9
	 */
	do_action( 'edd_meta_box_settings_fields', $post->ID );
}

/**
 * Price Section
 *
 * If variable pricing is not enabled, simply output a single input box.
 *
 * If variable pricing is enabled, outputs a table of all current prices.
 * Extensions can add column heads to the table via the `edd_download_file_table_head`
 * hook, and actual columns via `edd_download_file_table_row`
 *
 * @since 1.0
 *
 * @see edd_render_price_row()
 *
 * @param $post_id
 */
function edd_render_price_field( $post_id ) {
	global $edd_options;

	$price 				= edd_get_download_price( $post_id );
	$variable_pricing 	= edd_has_variable_prices( $post_id );
	$prices 			= edd_get_variable_prices( $post_id );
	$single_option_mode = edd_single_price_option_mode( $post_id );

	$price_display    	= $variable_pricing ? ' style="display:none;"' : '';
	$variable_display 	= $variable_pricing ? '' : ' style="display:none;"';
?>
	<p>
		<strong><?php echo apply_filters( 'edd_price_options_heading', __( 'Pricing Options:', 'edd' ) ); ?></strong>
	</p>

	<p>
		<label for="edd_variable_pricing">
			<input type="checkbox" name="_variable_pricing" id="edd_variable_pricing" value="1" <?php checked( 1, $variable_pricing ); ?> />
			<?php echo apply_filters( 'edd_variable_pricing_toggle_text', __( 'Enable variable pricing', 'edd' ) ); ?>
		</label>
	</p>

	<div id="edd_regular_price_field" class="edd_pricing_fields" <?php echo $price_display; ?>>
		<?php
			$price_args = array( 
				'name'  => 'edd_price', 
				'value' => isset( $price ) ? esc_attr( edd_format_amount( $price ) ) : '', 
				'class' => 'edd-price-field'
			); 
		?>

		<?php if ( ! isset( $edd_options['currency_position'] ) || $edd_options['currency_position'] == 'before' ) : ?>
			<?php echo edd_currency_filter( '' ); ?>
			<?php echo EDD()->html->text( $price_args ); ?>
		<?php else : ?>
			<?php echo EDD()->html->text( $price_args ); ?>
			<?php echo edd_currency_filter( '' ); ?>
		<?php endif; ?>

		<?php do_action( 'edd_price_field', $post_id ); ?>
	</div>

	<?php do_action( 'edd_after_price_field', $post_id ); ?>

	<div id="edd_variable_price_fields" class="edd_pricing_fields" <?php echo $variable_display; ?>>
		<input type="hidden" id="edd_variable_prices" class="edd_variable_prices_name_field" value=""/>
		<p>
			<?php echo EDD()->html->checkbox( array( 'name' => '_edd_price_options_mode', 'current' => $single_option_mode ) ); ?>
			<label for="_edd_price_options_mode"><?php apply_filters( 'edd_multi_option_purchase_text', _e( 'Enable multi option purchase mode. Leave unchecked to only permit a single price option to be purchased', 'edd' ) ); ?></label>
		</p>
		<div id="edd_price_fields" class="edd_meta_table_wrap">
			<table class="widefat edd_repeatable_table" width="100%" cellpadding="0" cellspacing="0">
				<thead>
					<tr>
						<!--drag handle column. Disabled until we can work out a way to solve the issues raised here: https://github.com/easydigitaldownloads/Easy-Digital-Downloads/issues/1066
						<th style="width: 20px"></th>
						-->
						<th><?php _e( 'Option Name', 'edd' ); ?></th>
						<th style="width: 100px"><?php _e( 'Price', 'edd' ); ?></th>
						<?php do_action( 'edd_download_price_table_head', $post_id ); ?>
						<th style="width: 2%"></th>
					</tr>
				</thead>
				<tbody>
					<?php
						if ( ! empty( $prices ) ) :
							foreach ( $prices as $key => $value ) :
								$name   = isset( $value['name'] ) ? $value['name'] : '';
								$amount = isset( $value['amount'] ) ? $value['amount'] : '';

								$args = apply_filters( 'edd_price_row_args', compact( 'name', 'amount' ), $value );
					?>
						<tr class="edd_variable_prices_wrapper edd_repeatable_row">
							<?php do_action( 'edd_render_price_row', $key, $args, $post_id ); ?>
						</tr>
					<?php
							endforeach;
						else :
					?>
						<tr class="edd_variable_prices_wrapper edd_repeatable_row">
							<?php do_action( 'edd_render_price_row', 0, array(), $post_id ); ?>
						</tr>
					<?php endif; ?>

					<tr>
						<td class="submit" colspan="4" style="float: none; clear:both; background:#fff;">
							<a class="button-secondary edd_add_repeatable" style="margin: 6px 0;"><?php _e( 'Add New Price', 'edd' ); ?></a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div><!--end #edd_variable_price_fields-->
<?php
}
add_action( 'edd_meta_box_price_fields', 'edd_render_price_field', 10 );

/**
 * Individual Price Row
 *
 * Used to output a table row for each price associated with a download.
 * Can be called directly, or attached to an action.
 *
 * @since 1.2.2
 *
 * @param       $key
 * @param array $args
 * @param       $post_id
 */
function edd_render_price_row( $key, $args = array(), $post_id ) {
	global $edd_options;

	$defaults = array(
		'name'   => null,
		'amount' => null
	);

	$args = wp_parse_args( $args, $defaults );
	extract( $args, EXTR_SKIP );
?>
	<!--
	Disabled until we can work out a way to solve the issues raised here: https://github.com/easydigitaldownloads/Easy-Digital-Downloads/issues/1066
	<td>
		<span class="edd_draghandle"></span>
	</td>
	-->

	<td>
		<?php echo EDD()->html->text( array(
			'name'  => 'edd_variable_prices[' . $key . '][name]',
			'value' => esc_attr( $name ),
			'placeholder' => __( 'Option Name', 'edd' ),
			'class' => 'large-text'
		) ); ?>
	</td>

	<td>
		<?php
			$price_args = array( 
				'name'  => 'edd_variable_prices[' . $key . '][amount]', 
				'value' => $amount,
				'placeholder' => '9.99',
				'class' => 'edd-price-field'
			); 
		?>

		<?php if( ! isset( $edd_options['currency_position'] ) || $edd_options['currency_position'] == 'before' ) : ?>
			<span><?php echo edd_currency_filter( '' ); ?></span>
			<?php echo EDD()->html->text( $price_args ); ?>
		<?php else : ?>
			<?php echo EDD()->html->text( $price_args ); ?>
			<?php echo edd_currency_filter( '' ); ?>
		<?php endif; ?>
	</td>

	<?php do_action( 'edd_download_price_table_row', $post_id, $key, $args ); ?>

	<td>
		<a href="#" class="edd_remove_repeatable" data-type="price" style="background: url(<?php echo admin_url('/images/xit.gif'); ?>) no-repeat;">&times;</a>
	</td>
<?php
}
add_action( 'edd_render_price_row', 'edd_render_price_row', 10, 3 );

/**
 * Product type options
 *
 * @access      private
 * @since       1.6
 * @return      void
 */
function edd_render_product_type_field( $post_id = 0 ) {

	$types = edd_get_download_types();
	$type  = edd_get_download_type( $post_id );
?>
	<p>
		<strong><?php apply_filters( 'edd_product_type_options_heading', _e( 'Product Type Options:', 'edd' ) ); ?></strong>
	</p>
	<p>
		<?php echo EDD()->html->select( array( 
			'options'          => $types, 
			'name'             => '_edd_product_type', 
			'selected'         => $type, 
			'show_option_all'  => false, 
			'show_option_none' => false 
		) ); ?>
		<label for="edd_product_type"><?php _e( 'Select a product type', 'edd' ); ?></label>
	</p>
<?php
}
add_action( 'edd_meta_box_files_fields', 'edd_render_product_type_field', 10 );

/**
 * Renders product field
 * @since 1.6
 *
 * @param $post_id
 */
function edd_render_products_field( $post_id ) {
	$type     = edd_get_download_type( $post_id );
	$display  = $type == 'bundle' ? '' : ' style="display:none;"';
	$products = edd_get_bundled_products( $post_id );
?>
	<div id="edd_products"<?php echo $display; ?>>
		<p>
			<strong><?php printf( __( 'Bundled %s:', 'edd' ), edd_get_label_plural() ); ?></strong>
		</p>

		<div id="edd_file_fields" class="edd_meta_table_wrap">
			<table class="widefat" width="100%" cellpadding="0" cellspacing="0">
				<thead>
					<tr>
						<th style="width: 20%"><?php printf( __( 'Select the %s to bundle with this %s', 'edd' ), edd_get_label_plural(), edd_get_label_singular() ); ?></th>
						<?php do_action( 'edd_download_products_table_head', $post_id ); ?>
						<th style="width: 2%"></th>
					</tr>
				</thead>
				<tbody>
				<?php
					if ( ! empty( $products ) ) :
						foreach ( $products as $product ) :
				?>
							<tr class="edd_repeatable_product_wrapper">
								<?php do_action( 'edd_render_product_row', $product, $post_id ); ?>
							</tr>
				<?php
						endforeach;
					else :
				?>
					<tr class="edd_repeatable_product_wrapper">
						<?php do_action( 'edd_render_product_row', 0, $post_id ); ?>
					</tr>
				<?php endif; ?>
					<tr>
						<td class="submit" colspan="4" style="float: none; clear:both; background: #fff;">
							<a class="button-secondary edd_add_repeatable" style="margin: 6px 0;"><?php _e( 'Add New', 'edd' ); ?></a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
<?php
}
add_action( 'edd_meta_box_files_fields', 'edd_render_products_field', 10 );

/**
 * TODO Update doc
 *
 * @since       1.6
 * @param int $product_id
 * @param     $post_id
 */
function edd_render_product_row( $product_id = 0, $post_id ) {

?>
	<td>
		<?php echo EDD()->html->product_dropdown( '_edd_bundled_products[]', $product_id ); ?>
	</td>

	<?php do_action( 'edd_product_table_row', $product_id, $post_id ); ?>

	<td>
		<a href="#" class="edd_remove_repeatable" data-type="file" style="background: url(<?php echo admin_url('/images/xit.gif'); ?>) no-repeat;">&times;</a>
	</td>
<?php
}
add_action( 'edd_render_product_row', 'edd_render_product_row', 10, 2 );


/**
 * File Downloads section.
 *
 * Outputs a table of all current files. Extensions can add column heads to the table
 * via the `edd_download_file_table_head` hook, and actual columns via
 * `edd_download_file_table_row`
 *
 * @since 1.0
 * @see edd_render_file_row()
 * @param int $post_id Download (Post) ID
 * @return void
 */
function edd_render_files_field( $post_id = 0 ) {
	$type             = edd_get_download_type( $post_id );
	$files            = edd_get_download_files( $post_id );
	$variable_pricing = edd_has_variable_prices( $post_id );
	$display          = $type == 'bundle' ? ' style="display:none;"' : '';
	$variable_display = $variable_pricing ? '' : 'display:none;';
?>
	<div id="edd_download_files"<?php echo $display; ?>>
		<p>
			<strong><?php _e( 'File Downloads:', 'edd' ); ?></strong>
		</p>

		<input type="hidden" id="edd_download_files" class="edd_repeatable_upload_name_field" value=""/>

		<div id="edd_file_fields" class="edd_meta_table_wrap">
			<table class="widefat edd_repeatable_table" width="100%" cellpadding="0" cellspacing="0">
				<thead>
					<tr>
						<!--drag handle column. Disabled until we can work out a way to solve the issues raised here: https://github.com/easydigitaldownloads/Easy-Digital-Downloads/issues/1066
						<th style="width: 20px"></th>
						-->
						<th style="width: 20%"><?php _e( 'File Name', 'edd' ); ?></th>
						<th><?php _e( 'File URL', 'edd' ); ?></th>
						<th class="pricing" style="width: 20%; <?php echo $variable_display; ?>"><?php _e( 'Price Assignment', 'edd' ); ?></th>
						<?php do_action( 'edd_download_file_table_head', $post_id ); ?>
						<th style="width: 2%"></th>
					</tr>
				</thead>
				<tbody>
				<?php
					if ( ! empty( $files ) && is_array( $files ) ) :
						foreach ( $files as $key => $value ) :
							$name = isset( $value['name'] ) ? $value['name'] : '';
							$file = isset( $value['file'] ) ? $value['file'] : '';
							$condition = isset( $value['condition'] ) ? $value['condition'] : false;

							$args = apply_filters( 'edd_file_row_args', compact( 'name', 'file', 'condition' ), $value );
				?>
						<tr class="edd_repeatable_upload_wrapper edd_repeatable_row">
							<?php do_action( 'edd_render_file_row', $key, $args, $post_id ); ?>
						</tr>
				<?php
						endforeach;
					else :
				?>
					<tr class="edd_repeatable_upload_wrapper edd_repeatable_row">
						<?php do_action( 'edd_render_file_row', 0, array(), $post_id ); ?>
					</tr>
				<?php endif; ?>
					<tr>
						<td class="submit" colspan="4" style="float: none; clear:both; background: #fff;">
							<a class="button-secondary edd_add_repeatable" style="margin: 6px 0;"><?php _e( 'Add New File', 'edd' ); ?></a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
<?php
}
add_action( 'edd_meta_box_files_fields', 'edd_render_files_field', 20 );


/**
 * Individual file row.
 *
 * Used to output a table row for each file associated with a download.
 * Can be called directly, or attached to an action.
 *
 * @since 1.2.2
 * @param string $key Array key
 * @param array $args Array of all the arguments passed to the function
 * @param int $post_id Download (Post) ID
 * @return void
 */
function edd_render_file_row( $key = '', $args = array(), $post_id ) {
	$defaults = array(
		'name'      => null,
		'file'      => null,
		'condition' => null
	);

	$args = wp_parse_args( $args, $defaults );
	extract( $args, EXTR_SKIP );

	$prices = edd_get_variable_prices( $post_id );

	$variable_pricing = edd_has_variable_prices( $post_id );
	$variable_display = $variable_pricing ? '' : ' style="display:none;"';
?>

	<!--
	Disabled until we can work out a way to solve the issues raised here: https://github.com/easydigitaldownloads/Easy-Digital-Downloads/issues/1066
	<td>
		<span class="edd_draghandle"></span>
	</td>
	-->
	<td>
		<?php echo EDD()->html->text( array(
			'name'        => 'edd_download_files[' . $key . '][name]',
			'value'       => $name,
			'placeholder' => __( 'File Name', 'edd' ),
			'class'       => 'edd_repeatable_name_field large-text'
		) ); ?>
	</td>

	<td>
		<div class="edd_repeatable_upload_field_container">
			<?php echo EDD()->html->text( array(
				'name'        => 'edd_download_files[' . $key . '][file]',
				'value'       => $file,
				'placeholder' => __( 'Upload or enter the file URL', 'edd' ),
				'class'       => 'edd_repeatable_upload_field edd_upload_field large-text'
			) ); ?>

			<span class="edd_upload_file">
				<a href="#" data-uploader_title="" data-uploader_button_text="<?php _e( 'Insert', 'edd' ); ?>" class="edd_upload_file_button" onclick="return false;"><?php _e( 'Upload a File', 'edd' ); ?></a>
			</span>
		</div>
	</td>

	<td class="pricing"<?php echo $variable_display; ?>>
		<?php 
			$options = array();

			if ( $prices ) {
				foreach ( $prices as $price_key => $price ) {
					$options[ $price_key ] = $prices[ $price_key ][ 'name' ];
				}
			}

			echo EDD()->html->select( array(
				'name'     => 'edd_download_files[' . $key . '][condition]',
				'class'    => 'edd_repeatable_condition_field',
				'options'  => $options,
				'selected' => $condition
			) ); 
		?>
	</td>

	<?php do_action( 'edd_download_file_table_row', $post_id, $key, $args ); ?>

	<td>
		<a href="#" class="edd_remove_repeatable" data-type="file" style="background: url(<?php echo admin_url('/images/xit.gif'); ?>) no-repeat;">&times;</a>
	</td>
<?php
}
add_action( 'edd_render_file_row', 'edd_render_file_row', 10, 3 );


/**
 * File Download Limit Row
 *
 * The file download limit is the maximum number of times each file
 * can be downloaded by the buyer
 *
 * @since 1.3.1
 * @param int $post_id Download (Post) ID
 * @return void
 */
function edd_render_download_limit_row( $post_id ) {
    global $edd_options;

    if( !current_user_can( 'manage_shop_settings' ) )
        return;

	$edd_download_limit = edd_get_file_download_limit( $post_id );
?>
	<p><strong><?php _e( 'File Download Limit:', 'edd' ); ?></strong></p>
	<label for="edd_download_limit">
		<?php echo EDD()->html->text( array(
			'name'  => '_edd_download_limit',
			'value' => $edd_download_limit,
			'class' => 'small-text'
		) ); ?>
		<?php _e( 'The maximum number of times a buyer can download each file. Leave blank or set to 0 for unlimited', 'edd' ); ?>
	</label>
<?php
}
add_action( 'edd_meta_box_settings_fields', 'edd_render_download_limit_row', 20 );


/**
 * Render Accounting Options
 *
 * @since 1.6
 * @param int $post_id Download (Post) ID
 * @return void
 */
function edd_render_accounting_options( $post_id ) {
	global $edd_options;

	if( ! edd_use_skus() ) {
		return;
	}

		$edd_sku = get_post_meta( $post_id, 'edd_sku', true );
?>
		<p><strong><?php _e( 'Accounting Options:', 'edd' ); ?></strong></p>
		<p>
			<label for="edd_sku">
				<?php echo EDD()->html->text( array(
					'name'  => 'edd_sku',
					'value' => $edd_sku,
					'class' => 'small-text'
				) ); ?>
				<?php echo sprintf( __( 'Enter an SKU for this %s.', 'edd' ), strtolower( edd_get_label_singular() ) ); ?>
			</label>
		</p>
<?php
}
add_action( 'edd_meta_box_settings_fields', 'edd_render_accounting_options', 25 );


/**
 * Render Disable Button
 *
 * @since 1.0
 * @param int $post_id Download (Post) ID
 * @return void
 */
function edd_render_disable_button( $post_id ) {
	$hide_button = get_post_meta( $post_id, '_edd_hide_purchase_link', true ) ? 1 : 0;
	$behavior    = get_post_meta( $post_id, '_edd_button_behavior', true );
?>
	<p><strong><?php _e( 'Button Options:', 'edd' ); ?></strong></p>
	<p>
		<label for="_edd_hide_purchase_link">
			<?php echo EDD()->html->checkbox( array(
				'name'    => '_edd_hide_purchase_link',
				'current' => $hide_button
			) ); ?>
			<?php _e( 'Disable the automatic output of the purchase button', 'edd' ); ?>
		</label>
	</p>
	<?php if( edd_shop_supports_buy_now() ) : ?>
	<p>
		<label for="_edd_button_behavior">
			<?php echo EDD()->html->select( array(
				'name'    => '_edd_button_behavior',
				'options' => array(
					'add_to_cart' => __( 'Add to Cart', 'edd' ),
					'direct'      => __( 'Buy Now', 'edd' )
				),
				'show_option_all'  => null,
				'show_option_none' => null,
				'selected' => $behavior
			) ); ?>
			<?php _e( 'Purchase button behavior', 'edd' ); ?>
		</label>
	</p>
<?php
	endif;
}
add_action( 'edd_meta_box_settings_fields', 'edd_render_disable_button', 30 );


/** Product Notes *****************************************************************/

/**
 * Product Notes Meta Box
 *
 * Renders the Product Notes meta box
 *
 * @since 1.2.1
 * @global array $post Contains all the download data
 * @global array $edd_options Contains all the options set for EDD
 * @return void
 */
function edd_render_product_notes_meta_box() {
	global $post, $edd_options;

	do_action( 'edd_product_notes_meta_box_fields', $post->ID );
}

/**
 * Render Product Notes Field
 *
 * @since 1.2.1
 * @param int $post_id Download (Post) ID
 * @return void
 */
function edd_render_product_notes_field( $post_id ) {
	global $edd_options;

	$product_notes = edd_get_product_notes( $post_id );
?>
	<textarea rows="1" cols="40" class="large-texarea" name="edd_product_notes" id="edd_product_notes"><?php echo esc_textarea( $product_notes ); ?></textarea>
	<p><?php _e( 'Special notes or instructions for this product. These notes will be added to the purchase receipt.', 'edd' ); ?></p>
<?php
}
add_action( 'edd_product_notes_meta_box_fields', 'edd_render_product_notes_field' );


/** Stats *****************************************************************/

/**
 * Render Stats Meta Box
 *
 * @since 1.0
 * @global array $post Contains all the download data
 * @return void
 */
function edd_render_stats_meta_box() {
	global $post;

	$earnings = edd_get_download_earnings_stats( $post->ID );
	$sales    = edd_get_download_sales_stats( $post->ID );
?>

	<p>
		<strong class="label"><?php _e( 'Sales:', 'edd' ); ?></strong>
		<span><?php echo $sales; ?> &mdash; <a href="<?php echo admin_url( '/edit.php?page=edd-reports&view=sales&post_type=download&tab=logs&download=' . $post->ID ); ?>"><?php _e( 'View Sales Log', 'edd' ); ?></a></span>
	</p>

	<p>
		<strong class="label"><?php _e( 'Earnings:', 'edd' ); ?></strong>
		<span><?php echo edd_currency_filter( edd_format_amount( $earnings ) ); ?></span>
	</p>

	<hr />

	<p>
		<span><a href="<?php echo admin_url( '/edit.php?page=edd-reports&view=file_downloads&post_type=download&tab=logs&download=' . $post->ID ); ?>"><?php _e( 'View File Download Log', 'edd' ); ?></a></span>
	</p>
<?php
	do_action('edd_stats_meta_box');
}
