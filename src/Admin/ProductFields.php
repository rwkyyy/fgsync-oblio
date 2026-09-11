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

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );
	}

	public function render_product_fields(): void {
		echo '<div class="options_group oblio-product-fields">';

		woocommerce_wp_select(
			array(
				'id'          => 'custom_product_type',
				'label'       => __( 'Tip produs Oblio', 'facturare-gestiune-oblio-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Cum este trecut produsul pe documentele Oblio. Gol = valoarea implicită din setările Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
				'options'     => array( '' => __( 'Valoare implicită (din setări)', 'facturare-gestiune-oblio-woocommerce' ) ) + $this->type_options(),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'custom_package_number',
				'label'             => __( 'Bucăți pe pachet', 'facturare-gestiune-oblio-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Câte bucăți conține un pachet. La sincronizarea stocului împarte cantitatea și înmulțește prețul. Gol = 1.', 'facturare-gestiune-oblio-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
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
		$product->update_meta_data( 'custom_product_type', $type );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_product_save().
		$package = isset( $_POST['custom_package_number'] ) ? absint( wp_unslash( $_POST['custom_package_number'] ) ) : 0;
		$product->update_meta_data( 'custom_package_number', $package > 0 ? (string) $package : '' );

		$product->save();
	}

	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		unset( $variation_data );
		woocommerce_wp_text_input(
			array(
				'id'                => 'cfwc_package_number[' . (int) $loop . ']',
				'name'              => 'cfwc_package_number[' . (int) $loop . ']',
				'label'             => __( 'Bucăți pe pachet (Oblio)', 'facturare-gestiune-oblio-woocommerce' ),
				'wrapper_class'     => 'form-row',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => get_post_meta( (int) $variation->ID, 'cfwc_package_number', true ),
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
			$variation->update_meta_data( 'cfwc_package_number', $package > 0 ? (string) $package : '' );
			$variation->save();
		}
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
