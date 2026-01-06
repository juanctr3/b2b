<?php
if (!defined('ABSPATH')) exit;

add_shortcode('sms_ver_oportunidad', 'sms_render_unlock_page');

function sms_render_unlock_page() {
    if (!is_user_logged_in()) {
        return '<div class="woocommerce-message">🔒 Debes <a href="/mi-cuenta">iniciar sesión</a> para ver esta oportunidad.</div>';
    }

    global $wpdb;
    $lead_id = isset($_GET['lid']) ? intval($_GET['lid']) : 0;
    $user_id = get_current_user_id();

    if ($lead_id === 0) return '<p>Enlace inválido.</p>';

    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id = $lead_id");
    
    if (!$lead || $lead->status != 'approved') {
        return '<div class="woocommerce-error">Esta cotización no está disponible.</div>';
    }

    // Verificar si ya desbloqueó
    $is_unlocked = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id = $lead_id AND provider_user_id = $user_id");

    // CONTADOR DE COMPETENCIA (Punto 3)
    $competitors_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id = $lead_id");

    $msg = '';
    // PROCESO DE COMPRA (Botón Web)
    if (isset($_POST['sms_confirm_unlock']) && !$is_unlocked) {
        $balance = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
        
        if ($balance >= $lead->cost_credits) {
            // Descontar
            update_user_meta($user_id, 'sms_wallet_balance', $balance - $lead->cost_credits);
            
            // Registrar
            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", [
                'lead_id' => $lead_id, 
                'provider_user_id' => $user_id
            ]);
            
            // Recargar página para mostrar datos
            echo "<script>window.location.reload();</script>"; 
            return; 

        } else {
            $msg = '<div class="woocommerce-error">❌ Saldo insuficiente. Tienes '.$balance.' créditos. <a href="/tienda" class="button">Recargar</a></div>';
        }
    }

    // RENDERIZADO
    ob_start();
    echo $msg;
    ?>
    <div class="sms-opportunity-card" style="border:1px solid #ddd; padding:20px; border-radius:8px; max-width:600px; margin:0 auto; background:#fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">Oportunidad #<?php echo $lead_id; ?></h3>
            <span style="background:#f0f0f0; padding:5px 10px; border-radius:15px; font-size:12px; color:#555;">
                👁️ Desbloqueado por <strong><?php echo $competitors_count; ?></strong> empresas
            </span>
        </div>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:20px;">
            <div style="background:#f9f9f9; padding:10px; border-radius:5px;">
                📍 <strong>Ubicación:</strong><br><?php echo esc_html($lead->city . ', ' . $lead->country); ?>
            </div>
            
            <div style="background:#f9f9f9; padding:10px; border-radius:5px;">
                🏢 <strong>Empresa:</strong><br>
                <?php if ($is_unlocked): ?>
                    <span style="color:#007cba; font-weight:bold;"><?php echo esc_html($lead->client_company ?: 'Particular'); ?></span>
                <?php else: ?>
                    <span style="color:#777;">🔒 Empresa Confidencial</span>
                <?php endif; ?>
            </div>
            
            <div style="background:#f9f9f9; padding:10px; grid-column: span 2; border-radius:5px;">
                📝 <strong>Requerimiento:</strong><br>
                <?php echo nl2br(esc_html($lead->requirement)); ?>
            </div>
        </div>

        <?php if ($is_unlocked): ?>
            <div style="background:#d4edda; border:1px solid #c3e6cb; padding:15px; border-radius:5px; color:#155724;">
                <h4 style="margin-top:0;">🔓 Datos de Contacto:</h4>
                <ul style="list-style:none; padding:0; font-size:16px;">
                    <li>👤 <strong>Nombre:</strong> <?php echo esc_html($lead->client_name); ?></li>
                    <li>📞 <strong>WhatsApp:</strong> <a href="https://wa.me/<?php echo str_replace('+','',$lead->client_phone); ?>">+<?php echo esc_html($lead->client_phone); ?></a></li>
                    <li>✉️ <strong>Email:</strong> <?php echo esc_html($lead->client_email); ?></li>
                </ul>
                <a href="https://wa.me/<?php echo str_replace('+','',$lead->client_phone); ?>" class="button button-primary">Chatear ahora</a>
            </div>
        
        <?php else: 
            $my_bal = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
        ?>
            <div style="text-align:center; background:#fff3cd; padding:20px; border-radius:5px; border:1px solid #ffeeba;">
                <p>⚠️ Para ver el nombre de la empresa y contacto:</p>
                <p style="font-size:18px;">Costo: <strong><?php echo $lead->cost_credits; ?> Créditos</strong></p>
                <p><small>Tu saldo actual: <?php echo $my_bal; ?> créditos</small></p>

                <?php if ($my_bal >= $lead->cost_credits): ?>
                    <form method="post">
                        <button type="submit" name="sms_confirm_unlock" class="button button-primary" style="font-size:18px; padding:10px 20px;">🔓 DESBLOQUEAR AHORA</button>
                    </form>
                <?php else: ?>
                    <a href="/tienda" class="button">💳 Recargar Saldo</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}