<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Travel by Luxe field types — shared data and helpers.
 *
 * The site's inquiry form needs more than the generic field set: a country
 * picker, region "pills" that follow the chosen countries, a budget slider whose
 * floor is worked out from the party and the trip, a consent box with links, and
 * a note box that opens on demand. These helpers back the `countries`,
 * `destinations`, `budget` and `consent` field types (rendered in
 * class-tf-render.php, behaviour in assets/tbl-fields.js, settings in
 * assets/builder.js + TF_Builder::sanitize_def()).
 *
 * The region lists were ported from the static site's js/form-destinations.js
 * (20 Oct 2026). Change them here, or through the `travelbyluxe/destinations`
 * filter; the front end reads the same array (localized as TF_TBL.destinations).
 */

/** Country → ISO code + the regions a guest can pick. Keys are the names shown to visitors. */
function tbl_destinations() {
	static $data = null;
	if ( null !== $data ) { return $data; }
	$countries = array(
		'Italy' => array( 'code' => 'IT', 'regions' => array(
			'Rome & Vatican', 'Amalfi Coast & Capri', 'Florence & Tuscany', 'Venice & Veneto', 'Italian Lakes', 'Sicily',
			'Puglia', 'Cinque Terre & Liguria', 'Sardinia', 'Umbria', 'Dolomites', 'Milan & Piedmont',
		) ),
		'France' => array( 'code' => 'FR', 'regions' => array(
			'Paris & Ile-de-France', 'Provence & French Riviera', 'Bordeaux & Dordogne', 'Loire Valley', 'Normandy & Brittany',
			'French Alps', 'Lyon & Rhone Valley', 'Alsace',
		) ),
		'Greece' => array( 'code' => 'GR', 'regions' => array(
			'Athens & Ancient Greece', 'Santorini', 'Mykonos', 'Crete', 'Rhodes & Dodecanese', 'Peloponnese', 'Meteora & Northern Greece',
		) ),
		'Croatia' => array( 'code' => 'HR', 'regions' => array(
			'Dubrovnik & Dalmatian Coast', 'Split & Islands', 'Zagreb & Inland', 'Istria', 'Hvar & Brac',
		) ),
		'Spain & Portugal' => array( 'code' => 'ES-PT', 'regions' => array(
			'Barcelona & Catalonia', 'Madrid & Central Spain', 'Andalusia', 'Seville & Granada', 'Basque Country', 'Lisbon & Porto', 'Algarve',
		) ),
		'UK' => array( 'code' => 'GB', 'regions' => array(
			'London & Southeast', 'Scottish Highlands', 'Cotswolds & Lake District',
		) ),
		'Ireland' => array( 'code' => 'IE', 'regions' => array(
			'Dublin', 'Kilkenny & the castles', 'Kerry & Dingle', 'Galway & Connemara', 'The Burren & the Cliffs of Moher',
		) ),
		'Switzerland' => array( 'code' => 'CH', 'regions' => array(
			'Zurich & Northeast', 'Lucerne & Central Switzerland', 'Geneva & Lake Geneva', 'Bernese Oberland', 'Zermatt & Valais',
			'Engadin & Graubünden', 'Ticino & Italian Switzerland',
		) ),
		'Morocco' => array( 'code' => 'MA', 'regions' => array(
			'Marrakech & Atlas Mountains', 'Fes & Imperial Cities', 'Sahara Desert & Merzouga', 'Casablanca & Rabat',
			'Chefchaouen & Blue City', 'Essaouira & Atlantic Coast', 'Ouarzazate & Desert Gateways',
		) ),
	);
	$data = apply_filters( 'travelbyluxe/destinations', array(
		'order'     => array_keys( $countries ),
		'countries' => $countries,
	) );
	return $data;
}

/**
 * The two answers no region list can supply, appended after the regions:
 * "Not sure yet" (exclusive — clears the regions) and "Somewhere else"
 * (reveals a free-text box, submitted as `<key>_other`).
 */
function tbl_destinations_open_pills() {
	return apply_filters( 'travelbyluxe/destinations_open_pills', array(
		array( 'value' => 'Not sure yet — recommend for me', 'exclusive' => true ),
		array( 'value' => 'Somewhere else', 'reveals' => true ),
	) );
}

/** Only the country names tbl_destinations() knows, in catalogue order. */
function tbl_valid_countries( $names ) {
	$data  = tbl_destinations();
	$names = is_array( $names ) ? $names : explode( ',', (string) $names );
	$names = array_map( 'trim', $names );
	$out   = array();
	foreach ( $data['order'] as $name ) {
		if ( in_array( $name, $names, true ) ) { $out[] = $name; }
	}
	return $out;
}

/** "Italy" · "Italy & France" · "Italy, France & Spain & Portugal". */
function tbl_format_country_list( $names ) {
	$names = array_values( array_filter( (array) $names ) );
	$n     = count( $names );
	if ( 0 === $n ) { return ''; }
	if ( 1 === $n ) { return $names[0]; }
	if ( 2 === $n ) { return $names[0] . ' & ' . $names[1]; }
	return implode( ', ', array_slice( $names, 0, -1 ) ) . ' & ' . $names[ $n - 1 ];
}

/** Replace {country} in a label or step title with the chosen countries (or a neutral fallback). */
function tbl_template( $text, $names ) {
	$label = $names ? tbl_format_country_list( $names ) : apply_filters( 'travelbyluxe/destination_fallback', 'your destination' );
	return str_replace( '{country}', $label, (string) $text );
}

/**
 * The phone dial-code list the site's inquiry form ships (33 countries, A–Z),
 * for a `tel` field set to "curated" instead of every country.
 */
function tbl_curated_phone_countries() {
	$keep = apply_filters( 'travelbyluxe/curated_phone_countries', array(
		'AU', 'AT', 'BE', 'BR', 'CA', 'CN', 'DK', 'FI', 'FR', 'DE', 'GR', 'HK', 'IN', 'IE', 'IL', 'IT', 'JP',
		'MX', 'NL', 'NZ', 'NO', 'PL', 'PT', 'SA', 'SG', 'ZA', 'KR', 'ES', 'SE', 'CH', 'AE', 'GB', 'US',
	) );
	$out = array();
	foreach ( tf_countries() as $c ) {
		if ( in_array( $c[0], $keep, true ) ) { $out[] = $c; }
	}
	usort( $out, function ( $a, $b ) { return strcasecmp( $a[2], $b[2] ); } );
	return $out;
}

/** HTML a consent label may carry (links to Terms / Privacy, emphasis, line breaks). */
function tbl_consent_allowed_html() {
	return array(
		'a'      => array( 'href' => true, 'target' => true, 'rel' => true, 'title' => true ),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);
}

/**
 * Budget field defaults — the static site's js/form-destinations.js numbers:
 * a 7-day trip for 2 adults starts at €10,000; France and Switzerland are
 * double. Every value is editable per field in the builder.
 */
function tbl_budget_defaults() {
	return apply_filters( 'travelbyluxe/budget_defaults', array(
		'currency'       => '€',
		'rate_adult'     => 5000,
		'rate_child'     => 3000,
		'rate_adult_day' => 700,
		'rate_child_day' => 500,
		'included_days'  => 7,
		'range_mult'     => 5,
		'step'           => 1000,
		'country_rates'  => "France: 10000, 6000, 1400, 1000\nSwitzerland: 10000, 6000, 1400, 1000",
		'adults_key'     => 'adults',
		'children_key'   => 'children',
		'days_key'       => 'trip_length',
		'country_key'    => 'trip_country',
	) );
}

/** "France: 10000, 6000, 1400, 1000" per line → [ France => { adult, child, adult_day, child_day } ]. */
function tbl_parse_country_rates( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		if ( false === strpos( $line, ':' ) ) { continue; }
		list( $name, $nums ) = array_map( 'trim', explode( ':', $line, 2 ) );
		$parts = array_map( 'trim', explode( ',', $nums ) );
		if ( '' === $name || count( $parts ) < 4 ) { continue; }
		$out[ $name ] = array(
			'adult'     => (float) $parts[0],
			'child'     => (float) $parts[1],
			'adult_day' => (float) $parts[2],
			'child_day' => (float) $parts[3],
		);
	}
	return $out;
}

/** A budget field's effective settings (its own values, defaults where blank). */
function tbl_budget_settings( $f ) {
	$d   = tbl_budget_defaults();
	$out = array();
	foreach ( $d as $k => $v ) {
		$out[ $k ] = ( isset( $f[ $k ] ) && '' !== $f[ $k ] && null !== $f[ $k ] ) ? $f[ $k ] : $v;
	}
	foreach ( array( 'rate_adult', 'rate_child', 'rate_adult_day', 'rate_child_day', 'included_days', 'range_mult', 'step' ) as $k ) {
		$out[ $k ] = (float) $out[ $k ];
	}
	return $out;
}

/** True if the form has an enabled "Thank-you summary" action (sessionStorage handoff). */
function tf_form_has_handoff( $post_id ) {
	foreach ( tf_get_actions( $post_id ) as $a ) {
		if ( ! empty( $a['enabled'] ) && isset( $a['type'] ) && 'handoff' === $a['type'] ) { return true; }
	}
	return false;
}
