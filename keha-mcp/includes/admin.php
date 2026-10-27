<?php
/**
 * Indstillinger → KEHA MCP: forbindelsesoplysninger, tilladte redirect-værter,
 * registrerede klienter og aktive forbindelser (med tilbagekaldelse).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( 'KEHA MCP', 'KEHA MCP', 'manage_options', 'keha-mcp', 'keha_mcp_admin_page' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( KEHA_MCP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=keha-mcp' ) ) . '">Indstillinger</a>' );
	return $links;
} );

function keha_mcp_admin_redirect( $msg ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'keha-mcp', 'keha_msg' => $msg ), admin_url( 'options-general.php' ) ) );
	exit;
}

add_action( 'admin_post_keha_mcp_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ingen adgang.' );
	}
	check_admin_referer( 'keha_mcp_save' );
	$raw   = isset( $_POST['redirect_hosts'] ) ? (string) wp_unslash( $_POST['redirect_hosts'] ) : ''; // phpcs:ignore
	$hosts = array();
	foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $h ) {
		$h = trim( $h );
		if ( '' !== $h && preg_match( '/^(\[[0-9a-f:]+\]|[a-z0-9.\-]+)$/', $h ) ) {
			$hosts[] = $h;
		}
	}
	update_option( 'keha_mcp_redirect_hosts', array_values( array_unique( $hosts ) ), false );
	update_option( 'keha_mcp_dcr_enabled', ! empty( $_POST['dcr_enabled'] ), false );
	keha_mcp_admin_redirect( 'saved' );
} );

add_action( 'admin_post_keha_mcp_revoke', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ingen adgang.' );
	}
	check_admin_referer( 'keha_mcp_revoke' );
	$family = isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : ''; // phpcs:ignore
	if ( $family ) {
		Keha_MCP_OAuth::revoke_family( $family );
	}
	keha_mcp_admin_redirect( 'revoked' );
} );

add_action( 'admin_post_keha_mcp_delete_client', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ingen adgang.' );
	}
	check_admin_referer( 'keha_mcp_delete_client' );
	$id = isset( $_POST['client_id'] ) ? sanitize_key( wp_unslash( $_POST['client_id'] ) ) : ''; // phpcs:ignore
	if ( $id ) {
		Keha_MCP_OAuth::delete_client( $id );
	}
	keha_mcp_admin_redirect( 'client_deleted' );
} );

function keha_mcp_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$clients  = Keha_MCP_OAuth::clients();
	$sessions = Keha_MCP_OAuth::active_sessions();
	$messages = array(
		'saved'          => 'Indstillingerne er gemt.',
		'revoked'        => 'Forbindelsen er tilbagekaldt.',
		'client_deleted' => 'Klienten og dens tokens er fjernet.',
	);
	$msg = isset( $_GET['keha_msg'] ) ? sanitize_key( $_GET['keha_msg'] ) : ''; // phpcs:ignore
	$fmt = function ( $ts ) {
		return $ts ? esc_html( wp_date( 'j. M Y H:i', (int) $ts ) ) : '–';
	};
	$card = 'background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:960px';
	?>
	<div class="wrap">
		<h1>KEHA MCP <span style="font-size:13px;color:#646970;font-weight:400">v<?php echo esc_html( KEHA_MCP_VERSION ); ?></span></h1>
		<?php if ( isset( $messages[ $msg ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
		<?php endif; ?>

		<div style="<?php echo esc_attr( $card ); ?>">
			<h2 style="margin-top:0">Forbind fra claude.ai</h2>
			<ol>
				<li>Gå til <strong>Indstillinger → Connectors</strong> i Claude, og vælg <strong>Tilføj custom connector</strong>.</li>
				<li>Navn: fx <code>keha.dk</code>. URL:</li>
			</ol>
			<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( Keha_MCP_OAuth::resource() ); ?>" onclick="this.select()"></p>
			<ol start="3">
				<li>Lad OAuth Client ID og Secret være tomme, klik <strong>Tilføj</strong> og derefter <strong>Forbind</strong>.</li>
				<li>Log ind her på keha.dk, hvis du bliver bedt om det, og klik <strong>Godkend</strong>.</li>
			</ol>
			<p style="color:#646970">Tjek opsætningen:
				<a href="<?php echo esc_url( Keha_MCP_OAuth::prm_url() ); ?>" target="_blank" rel="noopener">resource-metadata</a> ·
				<a href="<?php echo esc_url( Keha_MCP_OAuth::issuer() . '/.well-known/oauth-authorization-server' ); ?>" target="_blank" rel="noopener">OAuth-metadata</a>.
				Begge skal vise JSON. Desktopappen med Application Password virker fortsat side om side.</p>
		</div>

		<div style="<?php echo esc_attr( $card ); ?>">
			<h2 style="margin-top:0">Aktive forbindelser</h2>
			<?php if ( empty( $sessions ) ) : ?>
				<p>Ingen aktive OAuth-forbindelser.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Klient</th><th>Bruger</th><th>Forbundet</th><th>Sidst brugt</th><th>Udløber</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $sessions as $s ) : ?>
						<?php $u = get_userdata( (int) $s->user_id ); ?>
						<tr>
							<td><?php echo esc_html( $clients[ $s->client_id ]['name'] ?? $s->client_id ); ?></td>
							<td><?php echo esc_html( $u ? $u->display_name : '#' . $s->user_id ); ?></td>
							<td><?php echo $fmt( $s->created_at ); // phpcs:ignore ?></td>
							<td><?php echo $fmt( $s->last_used ); // phpcs:ignore ?></td>
							<td><?php echo $fmt( $s->expires_at ); // phpcs:ignore ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Tilbagekald forbindelsen?');">
									<input type="hidden" name="action" value="keha_mcp_revoke">
									<input type="hidden" name="family" value="<?php echo esc_attr( $s->family ); ?>">
									<?php wp_nonce_field( 'keha_mcp_revoke' ); ?>
									<button class="button button-link-delete">Tilbagekald</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="<?php echo esc_attr( $card ); ?>">
			<h2 style="margin-top:0">Registrerede klienter</h2>
			<?php if ( empty( $clients ) ) : ?>
				<p>Ingen endnu. Claude registrerer sig selv første gang, du forbinder.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Navn</th><th>Redirect</th><th>Oprettet</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $clients as $id => $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['name'] ); ?><br><code style="font-size:11px"><?php echo esc_html( $id ); ?></code></td>
							<td><code style="font-size:11px"><?php echo esc_html( implode( ', ', (array) $c['redirect_uris'] ) ); ?></code></td>
							<td><?php echo $fmt( $c['created'] ?? 0 ); // phpcs:ignore ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Fjern klienten og alle dens forbindelser?');">
									<input type="hidden" name="action" value="keha_mcp_delete_client">
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $id ); ?>">
									<?php wp_nonce_field( 'keha_mcp_delete_client' ); ?>
									<button class="button button-link-delete">Fjern</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="<?php echo esc_attr( $card ); ?>">
			<h2 style="margin-top:0">Sikkerhed</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="keha_mcp_save">
				<?php wp_nonce_field( 'keha_mcp_save' ); ?>
				<p><label><input type="checkbox" name="dcr_enabled" value="1" <?php checked( Keha_MCP_OAuth::dcr_enabled() ); ?>>
					Tillad at nye klienter registrerer sig selv (påkrævet første gang Claude forbinder)</label></p>
				<p><label for="redirect_hosts"><strong>Tilladte redirect-værter</strong> – én pr. linje. Kun klienter, der sender brugeren tilbage til disse værter, kan registreres.</label></p>
				<textarea id="redirect_hosts" name="redirect_hosts" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", Keha_MCP_OAuth::redirect_hosts() ) ); ?></textarea>
				<p style="color:#646970">Kun administratorer kan godkende en forbindelse. Adgangstokens gælder 1 time og fornyes automatisk i op til 60 dage.</p>
				<?php submit_button( 'Gem' ); ?>
			</form>
		</div>

		<div style="<?php echo esc_attr( $card ); ?>">
			<h2 style="margin-top:0">Værktøjer (<?php echo count( keha_mcp_ability_names() ); ?>)</h2>
			<p><code><?php echo esc_html( implode( '  ·  ', array_map( function ( $n ) {
				return str_replace( 'keha/', '', $n );
			}, keha_mcp_ability_names() ) ) ); ?></code></p>
		</div>
	</div>
	<?php
}
