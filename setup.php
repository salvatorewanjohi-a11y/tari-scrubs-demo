<?php
/**
 * Builds the Tari Scrubs & Supplies store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Product photos are read from /wordpress/tsc-images.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function tsc_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'tsc_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

// Fast demo build: the photos are already web-sized, so skip making thumbnails of each one.
// (Real hosting can regenerate thumbnails later.)
if ( defined( 'TSC_FAST' ) && TSC_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings.
foreach ( [
	'blogname'                               => 'Tari Scrubs & Supplies',
	'blogdescription'                        => 'Premium scrubs & lab coats, made for med life in Kenya',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'Nairobi',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'yes',
	'woocommerce_notify_low_stock_amount'    => '3',
	'woocommerce_notify_no_stock_amount'     => '0',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => '',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_enable_coupons'             => 'yes',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Colour and size attributes (global, so the shop can filter by them).
$attr_ids = [];
foreach ( [ 'colour' => 'Colour', 'size' => 'Size' ] as $slug => $label ) {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true ] );
	}
	$attr_ids[ $slug ] = $id;
	register_taxonomy( "pa_$slug", 'product', [ 'hierarchical' => false, 'rewrite' => [ 'slug' => $slug ], 'public' => true, 'query_var' => true ] );
}
delete_transient( 'wc_attribute_taxonomies' );

$size_order = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ];
foreach ( $size_order as $i => $s ) {
	$t = term_exists( $s, 'pa_size' ) ?: wp_insert_term( $s, 'pa_size', [ 'slug' => strtolower( $s ) ] );
	update_term_meta( (int) $t['term_id'], 'order', $i );
}

function tsc_term_ids( $tax, array $names ) {
	$ids = [];
	foreach ( $names as $n ) {
		$t = term_exists( $n, $tax ) ?: wp_insert_term( $n, $tax );
		$ids[] = (int) $t['term_id'];
	}
	return $ids;
}

function tsc_attribute( $tax_slug, $attr_id, array $names, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_id( $attr_id );
	$a->set_name( "pa_$tax_slug" );
	$a->set_options( tsc_term_ids( "pa_$tax_slug", $names ) );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( false );
	return $a;
}

// Categories.
$cat = [];
foreach ( [
	'scrub-sets'        => 'Scrub Sets',
	'lab-coats'         => 'Lab Coats',
	'nurse-uniforms'    => 'Nursing Uniforms',
	'clinical-packages' => 'Clinical Packages',
] as $slug => $name ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
	$cat[ $slug ] = (int) $t['term_id'];
}

// "Shop by role" tags.
$role = [];
foreach ( [
	'doctors-interns'          => 'Doctors & Interns',
	'medical-students'         => 'Medical Students',
	'nurses-clinical-officers' => 'Nurses & Clinical Officers',
	'theatre-wards'            => 'Theatre & Ward Staff',
] as $slug => $name ) {
	$t = term_exists( $slug, 'product_tag' ) ?: wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
	$role[ $slug ] = (int) $t['term_id'];
}

// Delivery.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->add_location( 'KE:KE30', 'state' );
$zone->save();
$id = $zone->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'Express Nairobi Delivery (same day)', 'cost' => '300', 'tax_status' => 'none' ] );
$id = $zone->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$id}_settings", [ 'title' => 'Free Nairobi Delivery', 'requires' => 'min_amount', 'min_amount' => '5000' ] );

$rest = new WC_Shipping_Zone();
$rest->set_zone_name( 'Rest of Kenya' );
$rest->add_location( 'KE', 'country' );
$rest->save();
$id = $rest->add_shipping_method( 'flat_rate' );
update_option( "woocommerce_flat_rate_{$id}_settings", [ 'title' => 'Upcountry Parcel Dispatch (1–3 days)', 'cost' => '500', 'tax_status' => 'none' ] );

update_option( 'woocommerce_pickup_location_settings', [ 'enabled' => 'yes', 'title' => 'Pick-up point in Nairobi (free)', 'tax_status' => 'none', 'cost' => '' ] );
update_option( 'pickup_location_pickup_locations', [ [
	'name'    => 'Tari Scrubs pick-up point, Nairobi',
	'address' => [ 'address_1' => 'Nairobi CBD (we confirm the exact pick-up point on WhatsApp)', 'city' => 'Nairobi', 'state' => 'KE30', 'postcode' => '', 'country' => 'KE' ],
	'details' => 'Daily, 8:00 AM – 5:00 PM. We will WhatsApp you when your order is ready and where to collect it.',
	'enabled' => true,
] ] );

// Payment: pay on delivery or at pickup until M-Pesa is connected.
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay on delivery or pickup (M-Pesa or cash)',
	'description'        => 'Pay by M-Pesa or cash when your order arrives, or when you collect it from our pick-up point.',
	'instructions'       => 'We will call or WhatsApp you to confirm your order and delivery time.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );

// Newsletter welcome coupon.
$c = new WC_Coupon();
$c->set_code( 'TARI300' );
$c->set_discount_type( 'fixed_cart' );
$c->set_amount( 300 );
$c->set_minimum_amount( 2500 );
$c->set_individual_use( true );
$c->set_usage_limit_per_user( 1 );
$c->set_description( 'Newsletter welcome offer' );
$c->save();

// Products, from Tari Scrubs' Instagram (@tari_scrubsandsupplies). Prices are the ones in their posts;
// the clinical packages have no published price, so they show "Ask for price".
$SIZES      = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ];
$SCRUB_CARE = "Wash inside out at 30–40°C with similar colours. No bleach. Hang to dry and warm iron if needed; iron embroidery from the reverse side.";

// colour_img: which photo shows each colour (0 = main photo, 1 = second photo…).
$products = [
	[
		'slug' => 'tari-scrub-set', 'name' => 'Tari Scrub Set', 'cat' => 'scrub-sets', 'price' => 2500,
		'colours' => [ 'Caribbean Blue', 'Ocean Blue', 'Jungle Green', 'Olive (Kenyan Trim)', 'Grey', 'Black (Teal Piping)' ], 'sizes' => $SIZES,
		'colour_img' => [ 'Caribbean Blue' => 0, 'Ocean Blue' => 2, 'Jungle Green' => 3, 'Olive (Kenyan Trim)' => 4, 'Grey' => 5, 'Black (Teal Piping)' => 6 ],
		'roles' => [ 'doctors-interns', 'medical-students', 'nurses-clinical-officers', 'theatre-wards' ], 'spec' => 'New 2026 Colours', 'featured' => true,
		'desc' => "Our everyday scrub set: a V-neck top with pockets exactly where you need them and matching trousers, in a soft, breathable fabric that keeps you comfortable from the first ward round to the end of a long shift. All colours and sizes available, with name and logo embroidery on request.",
		'fabric' => "Soft, breathable scrub fabric. V-neck top with chest and side pockets, drawstring trousers with pockets. Sizes XS–XXL.\n\n$SCRUB_CARE",
		'upsells' => [ 'lab-coat', 'valentino-set', 'littmann-classic-ii-package' ],
	],
	[
		'slug' => 'valentino-set', 'name' => 'The Valentino Set', 'cat' => 'scrub-sets', 'price' => 2500,
		'colours' => [ 'Valentino Pink' ], 'sizes' => [ 'XS', 'S', 'M', 'L', 'XL' ],
		'roles' => [ 'doctors-interns', 'medical-students', 'nurses-clinical-officers' ], 'spec' => 'New Drop · XS–XL', 'featured' => true,
		'desc' => "Morning shift. Coffee in hand. A pink set that makes you feel put-together before you even speak. The Valentino Set is our bright pink scrub set, in sizes XS to XL.",
		'fabric' => "Soft scrub fabric in Valentino pink. V-neck top with pockets, matching trousers. Sizes XS–XL.\n\n$SCRUB_CARE",
		'upsells' => [ 'tari-scrub-set', 'lab-coat', 'nursing-package' ],
	],
	[
		'slug' => 'beta-scrub-set', 'name' => 'Beta Scrub Set', 'cat' => 'scrub-sets', 'price' => 2500,
		'colours' => [ 'Slate Blue', 'Navy' ], 'sizes' => $SIZES,
		'colour_img' => [ 'Slate Blue' => 0, 'Navy' => 4 ],
		'roles' => [ 'medical-students', 'nurses-clinical-officers', 'theatre-wards' ], 'spec' => 'Lightweight · Best Value', 'featured' => true,
		'desc' => "Beta Scrubs are our budget-friendly line, made for medics who want quality without the heavy price tag. Lightweight, breathable and easy to move in: made for long shifts, clinicals, ward rounds and everything in between.",
		'fabric' => "Lightweight, breathable scrub fabric. Elastic waistband with drawstring, functional pockets. Sizes XS–XXL.\n\n$SCRUB_CARE",
		'upsells' => [ 'classic-scrub-set', 'air-scrub-set', 'lab-coat' ],
	],
	[
		'slug' => 'classic-scrub-set', 'name' => 'Classic Scrub Set', 'cat' => 'scrub-sets', 'price' => 3000,
		'colours' => [ 'Hunter Green', 'Jade Green', 'Hot Pink', 'Teal' ], 'sizes' => $SIZES,
		'colour_img' => [ 'Hunter Green' => 0, 'Jade Green' => 1, 'Hot Pink' => 2, 'Teal' => 3 ],
		'roles' => [ 'doctors-interns', 'theatre-wards', 'nurses-clinical-officers' ], 'spec' => 'Structured Fabric', 'featured' => true,
		'desc' => "Classic Scrubs: dependable, durable and made to last. For the long shifts, the back-to-backs and the everyday grind. Trusted by medics who want something that looks good, fits right and holds up over time.",
		'fabric' => "Strong, structured fabric. Functional pockets where you need them, clean fit with everyday comfort. Available in multiple colours, sizes XS–XXL.\n\n$SCRUB_CARE",
		'upsells' => [ 'air-scrub-set', 'beta-scrub-set', 'lab-coat' ],
	],
	[
		'slug' => 'air-scrub-set', 'name' => 'Air Scrub Set', 'cat' => 'scrub-sets', 'price' => 3500,
		'colours' => [ 'Beige', 'Petrol Blue', 'Brown' ], 'sizes' => $SIZES,
		'colour_img' => [ 'Beige' => 0, 'Petrol Blue' => 2, 'Brown' => 3 ],
		'roles' => [ 'doctors-interns', 'medical-students', 'theatre-wards' ], 'spec' => 'Our Lightest Fabric', 'featured' => true,
		'desc' => "Say hello to Air: our lightest, breeziest scrubs yet, designed to keep up with you without weighing you down. Soft feel, no cling: made for comfort, built for the grind, and perfect for warm days and long shifts.",
		'fabric' => "Our lightest, most breathable scrub fabric. Soft feel, no cling. Trousers with drawstring and pockets. Sizes XS–XXL.\n\n$SCRUB_CARE",
		'upsells' => [ 'classic-scrub-set', 'tari-scrub-set', 'lab-coat' ],
	],
	[
		'slug' => 'lab-coat', 'name' => 'Premium Lab Coat', 'cat' => 'lab-coats', 'price' => 1600,
		'colours' => [ 'White' ], 'sizes' => $SIZES,
		'roles' => [ 'doctors-interns', 'medical-students' ], 'spec' => 'Tailored Fit', 'featured' => true,
		'desc' => "Clean lines, a sharp fit and serious comfort. Our lab coats are tailored for a comfortable fit that keeps you looking sharp, whether you're in class, in clinic or on call. Soft, breathable and built for everyday wear, for medical students, interns, nurses, doctors and lab professionals.",
		'fabric' => "Crisp, professional finish. Functional pockets, button front, comfortable fabric. Sizes XS–XXL. Wholesale white lab coats also available.\n\nWash at 40–60°C to keep it bright. Iron embroidery from the reverse side.",
		'upsells' => [ 'tari-scrub-set', 'littmann-classic-iii-package', 'beta-scrub-set' ],
	],
	[
		'slug' => 'nurses-uniform', 'name' => 'Nurses Uniform – White & Navy', 'cat' => 'nurse-uniforms', 'price' => 2400,
		'colours' => [ 'White & Navy' ], 'sizes' => $SIZES,
		'roles' => [ 'nurses-clinical-officers', 'medical-students' ], 'spec' => 'Navy Trim', 'featured' => true,
		'desc' => "Nurse uniforms that work as hard as you do. A white tunic with a navy V-neck, pocket and sleeve trims, worn with navy trousers. Soft, durable and easy to wash, made to keep up with your shifts from clinicals to night duty.",
		'fabric' => "White tunic with navy trim, navy elastic-waist trousers with pockets. Sizes XS–XXL.\n\nWash at 40°C. Wash the white tunic separately from the navy trousers.",
		'upsells' => [ 'nursing-package', 'tari-scrub-set', 'lab-coat' ],
	],
	[
		'slug' => 'littmann-classic-ii-package', 'name' => 'Littmann Classic II Clinical Package', 'cat' => 'clinical-packages',
		'roles' => [ 'doctors-interns', 'medical-students', 'nurses-clinical-officers' ], 'spec' => 'Clinical Package', 'featured' => true,
		'desc' => "Everything you need for the wards in one package: a Littmann Classic II stethoscope, a stethoscope case, a pen torch, a patella hammer and a tape measure. Made for medical students, doctors and nurses.",
		'fabric' => "In the package:\n• Littmann Classic II stethoscope\n• Stethoscope case\n• Pen torch\n• Patella hammer\n• Tape measure\n\nAsk us on WhatsApp for today's price and stethoscope colours.",
		'upsells' => [ 'littmann-classic-iii-package', 'lab-coat', 'tari-scrub-set' ],
	],
	[
		'slug' => 'littmann-classic-iii-package', 'name' => 'Littmann Classic III Clinical Package', 'cat' => 'clinical-packages',
		'roles' => [ 'doctors-interns', 'medical-students', 'nurses-clinical-officers' ], 'spec' => 'Clinical Package', 'featured' => false,
		'desc' => "Our top clinical package: a Littmann Classic III stethoscope, a stethoscope case, a pen torch, a patella hammer and a tape measure. Made for medical students, doctors and nurses.",
		'fabric' => "In the package:\n• Littmann Classic III stethoscope\n• Stethoscope case\n• Pen torch\n• Patella hammer\n• Tape measure\n\nAsk us on WhatsApp for today's price and stethoscope colours.",
		'upsells' => [ 'littmann-classic-ii-package', 'lab-coat', 'tari-scrub-set' ],
	],
	[
		'slug' => 'nursing-package', 'name' => 'Nursing Clinical Package', 'cat' => 'clinical-packages',
		'roles' => [ 'nurses-clinical-officers', 'medical-students' ], 'spec' => 'Clinical Package', 'featured' => false,
		'desc' => "The nursing essentials in one package: a student double-tube stethoscope, a nurse's watch, a clinical thermometer and pocket scissors.",
		'fabric' => "In the package:\n• Student / double-tube stethoscope\n• Nurse's watch\n• Clinical thermometer\n• Pocket scissors\n\nAsk us on WhatsApp for today's price.",
		'upsells' => [ 'nurses-uniform', 'littmann-classic-ii-package', 'tari-scrub-set' ],
	],
];

function tsc_attach_images( $pid, $slug, $name ) {
	$files = glob( "/wordpress/tsc-images/$slug*.webp" ) ?: [];
	$files = array_values( array_filter( $files, fn( $f ) => preg_match( '#/' . preg_quote( $slug, '#' ) . '(-\d+)?\.webp$#', $f ) ) );
	// slug.webp is the main photo, then slug-2.webp, slug-3.webp…
	$num = fn( $f ) => preg_match( '#-(\d+)\.webp$#', substr( $f, strlen( $slug ) ), $m ) ? (int) $m[1] : 1;
	usort( $files, fn( $a, $b ) => $num( basename( $a ) ) <=> $num( basename( $b ) ) );
	$ids = [];
	foreach ( $files as $i => $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$att = tsc_sideload( $tmp, $base, $pid, $name . ( $i ? ' – photo ' . ( $i + 1 ) : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[] = $att;
	}
	return $ids;
}

$by_slug = [];
foreach ( $products as $order => $d ) {
	$is_var = ! empty( $d['options'] );
	$p = $is_var ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['desc'] );
	$p->set_category_ids( [ $cat[ $d['cat'] ] ] );
	$p->set_tag_ids( array_map( fn( $r ) => $role[ $r ], $d['roles'] ) );
	$p->set_featured( ! empty( $d['featured'] ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 10 );
	if ( ! $is_var && isset( $d['price'] ) ) {
		$p->set_regular_price( $d['price'] );
		if ( ! empty( $d['sale'] ) ) $p->set_sale_price( $d['sale'] );
	}

	$attrs = [];
	$pos = 0;
	if ( ! empty( $d['colours'] ) ) $attrs[] = tsc_attribute( 'colour', $attr_ids['colour'], $d['colours'], $pos++ );
	if ( ! empty( $d['sizes'] ) ) $attrs[] = tsc_attribute( 'size', $attr_ids['size'], $d['sizes'], $pos++ );
	if ( $is_var ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $d['options']['label'] );
		$a->set_options( array_keys( $d['options']['choices'] ) );
		$a->set_position( $pos++ );
		$a->set_visible( true );
		$a->set_variation( true );
		$attrs[] = $a;
	}
	$p->set_attributes( $attrs );
	$p->update_meta_data( '_tsc_spec', $d['spec'] );
	$p->update_meta_data( '_tsc_fabric', $d['fabric'] );
	if ( ! empty( $d['colour_img'] ) ) $p->update_meta_data( '_tsc_colour_images', $d['colour_img'] );
	$pid = $p->save();

	if ( $is_var ) {
		$key = sanitize_title( $d['options']['label'] );
		$first = true;
		foreach ( $d['options']['choices'] as $label => $price ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( [ $key => $label ] );
			// A choice's price is either one price or [ regular, sale ].
			$v->set_regular_price( is_array( $price ) ? $price[0] : $price );
			if ( is_array( $price ) ) $v->set_sale_price( $price[1] );
			$v->set_manage_stock( false ); // Uses the parent product's stock.
			$v->set_status( 'publish' );
			$v->save();
			if ( $first ) {
				$p->set_default_attributes( [ $key => $label ] );
				$first = false;
			}
		}
		$p->save();
		WC_Product_Variable::sync( $pid );
	}

	$imgs = tsc_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		set_post_thumbnail( $pid, array_shift( $imgs ) );
		// Written as meta: a second full product save here slowed the import.
		if ( $imgs ) update_post_meta( $pid, '_product_image_gallery', implode( ',', $imgs ) );
	}
	$by_slug[ $d['slug'] ] = $pid;
}

// "Complete the set" suggestions.
foreach ( $products as $d ) {
	if ( empty( $d['upsells'] ) ) continue;
	// Written as meta: a full product save per product here slowed the import.
	update_post_meta( $by_slug[ $d['slug'] ], '_upsell_ids', array_values( array_filter( array_map( fn( $s ) => $by_slug[ $s ] ?? 0, $d['upsells'] ) ) ) );
}

// Pages.
function tsc_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
tsc_page( 'wishlist', 'Your wishlist', "<!-- wp:shortcode -->\n[tsc_wishlist]\n<!-- /wp:shortcode -->" );
tsc_page( 'size-guide', 'Size guide', "<!-- wp:shortcode -->\n[tsc_size_guide]\n<!-- /wp:shortcode -->" );

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
