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

		// Primary mode dropdowns.
		foreach ( array( 'mode_wp', 'mode_components' ) as $mode_key ) {
			$val = isset( $input[ $mode_key ] ) ? $input[ $mode_key ] : 'decoy';
			$out[ $mode_key ] = in_array( $val, array( 'off', 'obfuscate', 'decoy' ), true ) ? $val : 'decoy';
		}

		// Remaining independent booleans. (block_readme_files is derived from the
		// components mode in scshield_normalize_settings, not a checkbox.)
		foreach ( array( 'hide_rest_users', 'block_author_scan', 'strip_theme_version', 'disable_wp_cron', 'block_wpcron_external' ) as $bool ) {
			$out[ $bool ] = empty( $input[ $bool ] ) ? 0 : 1;
		}

		$mode = isset( $input['xmlrpc_mode'] ) ? $input['xmlrpc_mode'] : 'disable';
		$out['xmlrpc_mode'] = in_array( $mode, array( 'off', 'disable', 'pingback_only_off' ), true ) ? $mode : 'disable';

		$out['wpcron_secret'] = isset( $input['wpcron_secret'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['wpcron_secret'] ) : '';

		// Manual decoy WordPress version: digits, dots, spaces, letters, hyphens only.
		$out['wp_version_spoof'] = isset( $input['wp_version_spoof'] ) ? trim( preg_replace( '/[^0-9A-Za-z. \-]/', '', $input['wp_version_spoof'] ) ) : '';

		// Manual per-component version overrides (textarea: "slug = version" lines).
		$out['manual_versions'] = isset( $input['manual_versions'] ) ? trim( preg_replace( '/[^0-9A-Za-z._= \r\n-]/', '', $input['manual_versions'] ) ) : '';

		// Apply the mode-derived flags so saved settings and modules stay in sync.
		$out = scshield_normalize_settings( $out );

		// Keep .htaccess in sync after settings change.
		SCShield_Htaccess::write( $out );

		// Re-resolve latest versions on next load (picks up fresh update data).
		delete_transient( 'scshield_latest_wp' );
		SCShield_Versions::flush();

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

		// Decoy mode: rewrite plugin/theme static version files (readme, changelog,
		// release_log) and asset banner comments to the latest version.
		if ( ! empty( $out['mask_version_files'] ) ) {
			$n = count( ( new SCShield_CompFiles( $out ) )->apply() );
			add_settings_error( SCSHIELD_OPTION, 'compfiles', 'Decoy: rewrote version strings in ' . $n . ' plugin/theme file(s) to their latest versions.', 'updated' );
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

				<h2>Version obfuscation</h2>
				<p class="description" style="max-width:760px">
					Pick how each version should appear to scanners. <strong>Obfuscate</strong> removes/hides the version;
					<strong>Decoy</strong> reports the <em>latest</em> release (auto-detected) so the site looks fully patched and bots move on.
				</p>
				<table class="form-table" role="presentation">
					<?php
					$mode_opts = array(
						'off'       => 'Off — leave the real version visible',
						'obfuscate' => 'Obfuscate — remove / hide the version',
						'decoy'     => 'Decoy — report the latest version (looks patched)',
					);
					$this->mode_select( $name, 'mode_wp', $s, 'WordPress core version', $mode_opts, 'Controls the <code>&lt;meta generator&gt;</code>, feeds, and WLW manifest. Decoy emits <code>WordPress &lt;latest&gt;</code>.' );
					$this->mode_select( $name, 'mode_components', $s, 'Plugin &amp; theme versions', $mode_opts, 'Covers asset <code>?ver=</code>, <code>&lt;body&gt;</code> classes, inline-CSS URLs, plugin <code>&lt;meta generator&gt;</code> tags, HTML comments (e.g. Yoast), and static files (readme, changelog, <code>release_log.html</code>, and CSS/JS banner comments like Elementor). <strong>Obfuscate</strong> removes/blocks them; <strong>Decoy</strong> rewrites each to its latest version (uses WordPress\'s update data — known even for premium plugins that registered an update). Editing static files runs on save and after updates.' );
					?>
					<tr>
						<th scope="row">Manual decoy WP version</th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[wp_version_spoof]" value="<?php echo esc_attr( $s['wp_version_spoof'] ); ?>" placeholder="optional — e.g. 6.5">
							<p class="description">Only used when <em>WordPress core version</em> = Decoy and the latest can't be auto-detected. <strong>Prefer a recent value, never an old one.</strong></p>
						</td>
					</tr>
					<tr>
						<th scope="row">Manual versions (premium plugins/themes)</th>
						<td>
							<textarea class="large-text code" rows="4" name="<?php echo esc_attr( $name ); ?>[manual_versions]" placeholder="revslider = 6.7.57&#10;enfold = 6.1.4"><?php echo esc_textarea( $s['manual_versions'] ); ?></textarea>
							<p class="description">One <code>slug = version</code> per line. Use for premium components WordPress can't report a latest for (the slug is the plugin/theme folder name, e.g. <code>revslider</code>). Decoy will report these versions. Takes precedence over auto-detection.</p>
						</td>
					</tr>
				</table>

				<h2>Theme style.css (advanced)</h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( $name, 'strip_theme_version', $s, 'Mask the theme version in style.css', '<strong>Edits theme files.</strong> The theme version in <code>style.css</code> is a static file scanners read directly — the only way to change it is to edit the file. Follows the <em>Plugin &amp; theme versions</em> mode above (blank when Obfuscate, latest when Decoy), re-applied after updates. Hides WordPress\'s native theme-update notice, so the plugin shows its own update notice instead. Requires writable <code>style.css</code>.' );
					?>
				</table>

				<h2>Other hardening</h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( $name, 'hide_rest_users', $s, 'Block REST user enumeration', 'Disables /wp-json/wp/v2/users for anonymous requests.' );
					$this->checkbox( $name, 'block_author_scan', $s, 'Block ?author=N enumeration', 'Stops the author-ID redirect that leaks usernames.' );
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

	private function mode_select( $name, $key, $s, $label, $options, $desc ) {
		$current = isset( $s[ $key ] ) ? $s[ $key ] : 'decoy';
		?>
		<tr>
			<th scope="row"><?php echo wp_kses_post( $label ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]">
					<?php foreach ( $options as $val => $text ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
			</td>
		</tr>
		<?php
	}
}
