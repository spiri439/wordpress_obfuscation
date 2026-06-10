<?php
/**
 * Settings screen under Settings -> Scanner Shield.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_Admin {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCSHIELD_FILE ), array( $this, 'settings_link' ) );
	}

	public function menu() {
		add_options_page(
			'WordPress Obfuscation',
			'WP Obfuscation',
			'manage_options',
			'wp-obfuscation',
			array( $this, 'render' )
		);
	}

	public function settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=wp-obfuscation' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
		return $links;
	}

	public function register() {
		register_setting( 'scshield_group', SCSHIELD_OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
		) );
	}

	/**
	 * Sanitize, then rewrite .htaccess so the static-file block tracks the
	 * block_readme_files toggle.
	 */
	public function sanitize( $input ) {
		$out = scshield_default_settings();
		$input = is_array( $input ) ? $input : array();

		foreach ( array( 'remove_generator', 'wp_spoof_use_latest', 'remove_query_versions', 'strip_body_versions', 'clean_html_output', 'block_readme_files', 'hide_rest_users', 'block_author_scan', 'strip_theme_version', 'disable_wp_cron', 'block_wpcron_external' ) as $bool ) {
			$out[ $bool ] = empty( $input[ $bool ] ) ? 0 : 1;
		}

		$mode = isset( $input['xmlrpc_mode'] ) ? $input['xmlrpc_mode'] : 'disable';
		$out['xmlrpc_mode'] = in_array( $mode, array( 'off', 'disable', 'pingback_only_off' ), true ) ? $mode : 'disable';

		$out['wpcron_secret'] = isset( $input['wpcron_secret'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['wpcron_secret'] ) : '';

		// Decoy WordPress version: digits, dots, spaces, letters, hyphens only.
		$out['wp_version_spoof'] = isset( $input['wp_version_spoof'] ) ? trim( preg_replace( '/[^0-9A-Za-z. \-]/', '', $input['wp_version_spoof'] ) ) : '';

		// Keep .htaccess in sync after settings change.
		SCShield_Htaccess::write( $out );

		// Re-resolve the latest WP version on next load (picks up fresh data).
		delete_transient( 'scshield_latest_wp' );

		// Apply the theme-version strip immediately when enabled, and report
		// back if the style.css files weren't writable so the user isn't misled.
		if ( ! empty( $out['strip_theme_version'] ) ) {
			$changed = ( new SCShield_Theme( $out ) )->strip();
			if ( empty( $changed ) ) {
				add_settings_error( SCSHIELD_OPTION, 'theme_strip', 'Theme version stripping is enabled, but no style.css was writable (or it was already blank). Check file permissions on your theme.', 'warning' );
			} else {
				add_settings_error( SCSHIELD_OPTION, 'theme_strip', 'Stripped the Version header from ' . count( $changed ) . ' style.css file(s). Remember: update the theme — this only hides the version.', 'updated' );
			}
		}

		return $out;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = scshield_get_settings();
		?>
		<div class="wrap">
			<h1>WordPress Obfuscation</h1>
			<p style="max-width:760px">
				<strong>Reminder:</strong> this plugin <em>hides</em> fingerprints to cut down
				opportunistic scanning. It does not patch vulnerable code. Keep plugins, themes,
				and core updated — this is a complementary layer, not a fix.
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'scshield_group' ); ?>
				<?php $name = SCSHIELD_OPTION; ?>

				<h2>Fingerprint hardening</h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( $name, 'remove_generator', $s, 'Remove WordPress version', 'Strips the &lt;meta generator&gt; tag, feed generators, WLW manifest, and version readouts.' );
					$this->checkbox( $name, 'wp_spoof_use_latest', $s, 'Decoy as the latest WordPress version', 'Recommended. Reports your site as running the <strong>latest</strong> WordPress release (auto-detected from WordPress\'s own update data) so scanners see a fully-patched site and move on. Showing an <em>old</em> decoy — or nothing — can instead invite probing. Requires "Remove WordPress version".' );
					?>
					<tr>
						<th scope="row">Manual decoy version (optional)</th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[wp_version_spoof]" value="<?php echo esc_attr( $s['wp_version_spoof'] ); ?>" placeholder="leave blank to remove the version entirely">
							<p class="description">Used only when "Decoy as the latest" is off (or the latest can't be detected). If set (e.g. <code>6.5</code>), the generator emits <code>WordPress &lt;decoy&gt;</code>; blank removes the version entirely. <strong>Prefer a recent/latest value — never an old one.</strong></p>
						</td>
					</tr>
					<?php
					$this->checkbox( $name, 'remove_query_versions', $s, 'Remove ?ver= from CSS/JS', 'Hides plugin/theme versions in asset URLs. Note: also affects cache-busting on updates.' );
					$this->checkbox( $name, 'strip_body_versions', $s, 'Strip version classes from &lt;body&gt;', 'Removes version numbers from body classes read by WPScan\'s "Body Tag" detection: <code>js-comp-ver-6.7.0</code> (dropped), and the version number in <code>Zephyr_8.30</code> / <code>us-core_8.31.1</code> / <code>…-ver-1.2.3</code> (base name kept so theme CSS still works).' );
					$this->checkbox( $name, 'clean_html_output', $s, 'Strip plugin &lt;meta generator&gt; tags', 'Buffers the front-end HTML and removes plugin-emitted generator tags that core filters miss, e.g. <code>Powered by Slider Revolution 6.7.35</code> and WPBakery. Skips admin/AJAX/REST/feeds.' );
					$this->checkbox( $name, 'block_readme_files', $s, 'Block readme / changelog files (Apache)', 'Denies direct access to readme.txt, changelog.txt, license.txt, readme.html via .htaccess. Nginx needs a manual rule — see plugin README.' );
					$this->checkbox( $name, 'hide_rest_users', $s, 'Block REST user enumeration', 'Disables /wp-json/wp/v2/users for anonymous requests.' );
					$this->checkbox( $name, 'block_author_scan', $s, 'Block ?author=N enumeration', 'Stops the author-ID redirect that leaks usernames.' );
					$this->checkbox( $name, 'strip_theme_version', $s, 'Strip theme version from style.css', '<strong>Edits theme files.</strong> Blanks the <code>Version:</code> header in the active and parent theme\'s style.css (the line WPScan reads). Re-applied after theme updates. This does <strong>not</strong> patch the theme — update it. Requires writable style.css.' );
					?>
				</table>

				<h2>XML-RPC</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Mode</th>
						<td>
							<?php $mode = $s['xmlrpc_mode']; ?>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[xmlrpc_mode]" value="disable" <?php checked( $mode, 'disable' ); ?>> <strong>Disable &amp; hide</strong> — xmlrpc.php returns 404 (recommended if you don't use it)</label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[xmlrpc_mode]" value="pingback_only_off" <?php checked( $mode, 'pingback_only_off' ); ?>> Keep XML-RPC, kill pingback &amp; multicall (use if the Jetpack/mobile app needs it)</label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[xmlrpc_mode]" value="off" <?php checked( $mode, 'off' ); ?>> Off — leave WordPress default</label>
						</td>
					</tr>
				</table>

				<h2>WP-Cron</h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( $name, 'disable_wp_cron', $s, 'Disable the HTTP pseudo-cron', 'Stops WordPress from triggering wp-cron.php on page loads. <strong>Requires a real system cron</strong> (see README) or scheduled tasks stop running.' );
					$this->checkbox( $name, 'block_wpcron_external', $s, 'Block external hits to wp-cron.php', 'Returns 403 for direct external requests. Loopback and secret-token requests are allowed.' );
					?>
					<tr>
						<th scope="row">System-cron secret (optional)</th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[wpcron_secret]" value="<?php echo esc_attr( $s['wpcron_secret'] ); ?>" placeholder="leave blank to allow loopback only">
							<p class="description">If set, your system cron must call:
								<code><?php echo esc_html( site_url( '/wp-cron.php?doing_wp_cron&scshield_cron=YOUR_SECRET' ) ); ?></code>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>System cron setup (when pseudo-cron is disabled)</h2>
			<p>Add a line like this to your server crontab to run scheduled tasks every 5 minutes:</p>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto">*/5 * * * * curl -s "<?php echo esc_html( site_url( '/wp-cron.php?doing_wp_cron' . ( $s['wpcron_secret'] ? '&scshield_cron=' . $s['wpcron_secret'] : '' ) ) ); ?>" >/dev/null 2>&1</pre>
			<p>And add this to <code>wp-config.php</code> above the "stop editing" line for the cleanest result:</p>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto">define( 'DISABLE_WP_CRON', true );</pre>
		</div>
		<?php
	}

	private function checkbox( $name, $key, $s, $label, $desc ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>>
					Enabled
				</label>
				<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
			</td>
		</tr>
		<?php
	}
}
