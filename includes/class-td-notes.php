<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Notes — a read-only reference page under the Terrific Digital menu that
 * summarizes, in short, every feature the plugin provides. Kept current by hand
 * as features land (see PROJECT.md §5 for the long-form history).
 */
class TD_Notes {

	public static function init() {
		// Priority 13 so it lands after Settings (12) — last in the submenu.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
	}

	public static function menu() {
		add_submenu_page( TD_MENU, 'Notes', 'Notes', 'edit_pages', 'td-notes', array( __CLASS__, 'screen' ) );
	}

	/**
	 * Feature summary, grouped into cards. Each group: [ emoji, title, [ bullets ] ].
	 * Bullets are short, plain sentences — the at-a-glance "what does this plugin do".
	 */
	protected static function groups() {
		return array(
			array( '🧱', 'Form builder', array(
				'Build by clicking a field type (or dragging) — regular &amp; multi-step forms, with a side-by-side live preview.',
				'Duplicate any field; reorder by drag.',
				'Per-field width (full / ½ / ⅓) shown to scale in the canvas — drag a field beside another to pair them into a row; show-label toggle; collapsible cards.',
				'Field types: text, email, phone, number, slider, date, textarea, dropdown, radio, checkbox, heading, paragraph, separator, custom HTML, hidden.',
				'Phone field with IP-geolocated country dial-code selector (toggleable).',
				'Dropdown with separate label/value options, single or multi-select.',
				'Slider with min/max/step/default, value bubble or inline, prefix/suffix &amp; scale.',
				'Date field with earliest/latest bounds (days from today).',
				'Per-step Next button text; per-type palette icons.',
				'Sticky side-by-side live preview — renders the real form as you edit (toggleable, drag-resizable).',
			) ),
			array( '📍', 'Placement', array(
				'Shortcode <code>[terrific_form id="N"]</code>.',
				'Gutenberg block “Terrific Form” (live preview + form switcher).',
				'Front-end “Edit form” pill for logged-in editors.',
			) ),
			array( '🎯', 'Intent &amp; actions', array(
				'Per-form intent — Lead, Career Application, Contact Us, or Gated Content — added to dataLayer + webhook, and fired as its own event (e.g. <code>generate_lead</code>) after submit.',
				'Webhook action — global or custom URL; multi-step: final step only or every step.',
				'Redirect action — to a URL or a WordPress page. Each action toggleable.',
			) ),
			array( '📊', 'Tracking', array(
				'dataLayer events: <code>form_visible</code> (scrolled into view), <code>form_started</code> (first field interaction), <code>upd</code>, <code>form_step</code> (each Next), <code>form_submission</code>, then the intent as its own event (e.g. <code>generate_lead</code>).',
				'<code>window.dataLayer</code> is initialized early in the &lt;head&gt; on every front-end page.',
				'Webhook JSON payload: data, form, submission, device, cookies, session.',
			) ),
			array( '📥', 'Submissions', array(
				'Submissions table — filter by form, CSV export, delete, per-row detail view.',
				'Auto-captured: IP, user-agent, landing URL, referrer, device (category/OS/browser/platform/language), IP country, cookies.',
				'Partial / abandoned capture for multi-step forms (Partial → Completed).',
				'“View submissions (N)” link &amp; “Used on these pages” finder per form.',
			) ),
			array( '🕘', 'Revisions', array(
				'Every form save snapshots full state into a revision history.',
				'One-click restore (re-saves current state first), deduped, pruned to 50/form.',
			) ),
			array( '🛡️', 'Anti-spam', array(
				'Honeypot field on every form — bot fills are silently dropped (nothing stored or sent).',
				'Optional Google reCAPTCHA v3 (Settings) — invisible score-based bot filtering with a configurable threshold; low-scoring submissions are silently dropped.',
			) ),
			array( '🗂️', 'Store Session Data', array(
				'Records <code>{ timestamp, url, ref }</code> once per session (sessionStorage or cookie).',
				'Landing URL is param-normalized — rebuilds stripped <code>utm_*</code>/click-IDs from our <code>td_*</code>/<code>hsa_*</code> backup params (official value always wins).',
				'Attached to each submission + webhook + CSV as <code>session</code>.',
			) ),
			array( '🪪', 'User-Provided Data', array(
				'Captures first/last name, email, phone &amp; country from form fills into a persisted <code>upd</code> object <code>{ fn, ln, em, ph, co }</code>.',
				'Normalized (lowercase names/email, digits-only phone, 2-letter uppercase country); accumulates across steps.',
				'Stored in localStorage or a long-lived cookie; pushes a <code>upd</code> dataLayer event before each form event. On by default.',
			) ),
			array( '✂️', 'Code Snippets', array(
				'Inject custom HTML or JS into pages at a chosen location (after/before head, after body open, before body close).',
				'Per-snippet conditions (all pages, specific pages, URL contains / does not contain) + enable toggle.',
			) ),
			array( '❓', 'FAQ', array(
				'Build reusable FAQ lists (question + answer items, drag to reorder).',
				'Place any list with <code>[terrific_faq id="N"]</code> or the “Terrific FAQ” block.',
				'See every page a list is used on, from the list’s edit screen.',
				'Front-end accordion; opening an item fires <code>faq_item_click</code> (with the question, lowercased) once per item.',
			) ),
			array( '⚙️', 'Settings &amp; dashboard', array(
				'Settings: global webhook URL, reCAPTCHA v3, Store Session Data, User-Provided Data.',
				'Dashboard with feature cards (Forms, Code Snippets, FAQ Lists), live counts, version &amp; last-updated date.',
				'Top-level Terrific Digital menu (brand “t” icon); admin features gated on <code>edit_pages</code>.',
			) ),
		);
	}

	public static function screen() {
		?>
		<div class="wrap">
			<h1 style="margin:8px 0 12px">Notes</h1>
			<p style="font-size:14px;color:#555;max-width:760px">A short summary of everything this plugin does — every feature, at a glance.</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px;margin-top:20px">
				<?php foreach ( self::groups() as $g ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px">
						<h2 style="margin:0 0 10px;font-size:15px;display:flex;align-items:center;gap:8px">
							<span aria-hidden="true" style="font-size:18px"><?php echo $g[0]; // phpcs:ignore -- static emoji ?></span>
							<span><?php echo $g[1]; // phpcs:ignore -- static markup ?></span>
						</h2>
						<ul style="margin:0;padding-left:18px;list-style:disc;color:#3c434a;font-size:13px;line-height:1.7">
							<?php foreach ( $g[2] as $bullet ) : ?>
								<li><?php echo $bullet; // phpcs:ignore -- static, trusted markup (code/em tags) ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
