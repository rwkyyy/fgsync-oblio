<?php
/**
 * Per-product Oblio fields on the WooCommerce product editor.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use WC_Product;
final class ProductFields {

	private const TYPES = array(
		'Marfa',
		'Semifabricate',
		'Produs finit',
		'Produs rezidual',
		'Produse agricole',
		'Animale si pasari',
		'Ambalaje',
		'Serviciu',
	);

	private const META_PRODUCT_TYPE             = 'oblio_fgwoo_product_type';
	private const META_PACKAGE_NUMBER           = 'oblio_fgwoo_package_number';
	private const META_VARIATION_PACKAGE_NUMBER = 'oblio_fgwoo_variation_package_number';

	// Unprefixed keys the predecessor plugin wrote; read-only, kept for products set up before this plugin.
	private const LEGACY_META_PRODUCT_TYPE             = 'custom_product_type';
	private const LEGACY_META_PACKAGE_NUMBER           = 'custom_package_number';
	private const LEGACY_META_VARIATION_PACKAGE_NUMBER = 'cfwc_package_number';

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );
	}

	public function render_product_fields(): void {
		echo '<div class="options_group oblio-product-fields">';

		global $post;

		$package_number = self::package_number( (int) $post->ID );

		woocommerce_wp_select(
			array(
				'id'          => 'custom_product_type',
				'label'       => __( 'Tip produs Oblio', 'oblio-fgwoo' ),
				'desc_tip'    => true,
				'description' => __( 'Cum este trecut produsul pe documentele Oblio. Gol = valoarea implicită din setările Oblio.', 'oblio-fgwoo' ),
				'options'     => array( '' => __( 'Valoare implicită (din setări)', 'oblio-fgwoo' ) ) + $this->type_options(),
				'value'       => self::product_type( (int) $post->ID ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'custom_package_number',
				'label'             => __( 'Bucăți pe pachet', 'oblio-fgwoo' ),
				'desc_tip'          => true,
				'description'       => __( 'Câte bucăți conține un pachet. La sincronizarea stocului împarte cantitatea și înmulțește prețul. Gol = 1.', 'oblio-fgwoo' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => $package_number > 0 ? (string) $package_number : '',
			)
		);

		echo '</div>';
	}

	public function save_product_fields( $post_id ): void {
		if ( ! $this->verify_product_save( (int) $post_id ) ) {
			return;
		}

		$product = wc_get_product( (int) $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_product_save().
		$raw_type = isset( $_POST['custom_product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_product_type'] ) ) : '';
		$type     = in_array( $raw_type, self::TYPES, true ) ? $raw_type : '';
		$product->update_meta_data( self::META_PRODUCT_TYPE, $type );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_product_save().
		$package = isset( $_POST['custom_package_number'] ) ? absint( wp_unslash( $_POST['custom_package_number'] ) ) : 0;
		$product->update_meta_data( self::META_PACKAGE_NUMBER, $package > 0 ? (string) $package : '' );

		$product->save();
	}

	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		unset( $variation_data );
		$package_number = self::variation_package_number( (int) $variation->ID );
		woocommerce_wp_text_input(
			array(
				'id'                => 'cfwc_package_number[' . (int) $loop . ']',
				'name'              => 'cfwc_package_number[' . (int) $loop . ']',
				'label'             => __( 'Bucăți pe pachet (Oblio)', 'oblio-fgwoo' ),
				'wrapper_class'     => 'form-row',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => $package_number > 0 ? (string) $package_number : '',
			)
		);
	}

	public function save_variation_fields( $variation_id, $index ): void {
		if ( ! $this->verify_variation_save() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_variation_save().
		$package = isset( $_POST['cfwc_package_number'][ (int) $index ] ) ? absint( wp_unslash( $_POST['cfwc_package_number'][ (int) $index ] ) ) : 0;

		$variation = wc_get_product( (int) $variation_id );
		if ( $variation instanceof WC_Product ) {
			$variation->update_meta_data( self::META_VARIATION_PACKAGE_NUMBER, $package > 0 ? (string) $package : '' );
			$variation->save();
		}
	}

	public static function product_type( int $product_id ): string {
		$type = trim( (string) get_post_meta( $product_id, self::META_PRODUCT_TYPE, true ) );
		return '' !== $type ? $type : trim( (string) get_post_meta( $product_id, self::LEGACY_META_PRODUCT_TYPE, true ) );
	}

	public static function package_number( int $product_id ): int {
		$package = (int) get_post_meta( $product_id, self::META_PACKAGE_NUMBER, true );
		return $package > 0 ? $package : (int) get_post_meta( $product_id, self::LEGACY_META_PACKAGE_NUMBER, true );
	}

	public static function variation_package_number( int $variation_id ): int {
		$package = (int) get_post_meta( $variation_id, self::META_VARIATION_PACKAGE_NUMBER, true );
		return $package > 0 ? $package : (int) get_post_meta( $variation_id, self::LEGACY_META_VARIATION_PACKAGE_NUMBER, true );
	}

	private function type_options(): array {
		return array_combine( self::TYPES, self::TYPES );
	}

	private function verify_product_save( int $post_id ): bool {
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return false;
		}

		return isset( $_POST['woocommerce_meta_nonce'] )
			&& (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
	}

	private function verify_variation_save(): bool {
		return isset( $_POST['security'] )
			&& (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
	}
}
