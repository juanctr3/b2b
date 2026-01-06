<?php
if (!defined('ABSPATH')) exit;

/**
 * ENVÍO BLINDADO DE MENSAJES (Soporte para Emojis y Tildes)
 */
function sms_send_msg($to, $msg) {
    $url = "https://whatsapp.smsenlinea.com/api/send/whatsapp";
    
    // Preparar payload
    $data = [
        "secret"    => get_option('sms_api_secret'),
        "account"   => get_option('sms_account_id'),
        "recipient" => $to,
        "type"      => "text",
        "message"   => $msg,
        "priority"  => 1
    ];

    // Enviamos usando JSON_UNESCAPED_UNICODE para evitar que los emojis se rompan
    wp_remote_post($url, [
        'body'    => json_encode($data, JSON_UNESCAPED_UNICODE), 
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'timeout'  => 15,
        'blocking' => false // Cambia a true si necesitas depurar errores
    ]);
}

// 1. NOTIFICACIÓN A PROVEEDORES (Cuando se aprueba un lead)
add_action('sms_notify_providers', 'sms_smart_notification', 10, 1);

function sms_smart_notification($lead_id) {
    global $wpdb;
    $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
    if (!$lead) return;

    $base_url = site_url('/oportunidad'); 
    $shop_url = site_url('/tienda');

    // Emojis (Hexadecimales para máxima compatibilidad)
    $e_bell  = "\xF0\x9F\x94\x94"; 
    $e_pin   = "\xF0\x9F\x93\x8D"; 
    $e_memo  = "\xF0\x9F\x93\x9D"; 
    $e_card  = "\xF0\x9F\x92\xB3"; 
    $e_warn  = "\xE2\x9A\xA0";     
    $e_point = "\xF0\x9F\x91\x89"; 

    $users = get_users();
    foreach($users as $u) {
        // VALIDACIÓN: El proveedor debe tener teléfono verificado
        $doc_status = get_user_meta($u->ID, 'sms_docs_status', true);
        $phone_status = get_user_meta($u->ID, 'sms_phone_status', true);
        
        if($phone_status != 'verified') continue;
        
        // VALIDACIÓN: Verificar si el admin aprobó este servicio para este usuario
        $approved_services = get_user_meta($u->ID, 'sms_approved_services', true) ?: [];
        if(!in_array($lead->service_page_id, $approved_services)) continue;

        $phone = get_user_meta($u->ID, 'billing_phone', true);
        $email = $u->user_email;

        if($phone) {
            $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
            $cost = (int) $lead->cost_credits;
            
            // Recortar texto si es muy largo
            $desc_short = mb_substr($lead->requirement, 0, 150) . (mb_strlen($lead->requirement)>150 ? '...' : '');
            
            if ($balance >= $cost) {
                $link = $base_url . "?lid=" . $lead_id;
                $msg = "$e_bell *Nueva Cotización #$lead_id*\n\n$e_pin {$lead->city}\n$e_memo $desc_short\n\n$e_card Tu Saldo: *$balance cr* | Costo: *$cost cr*\n\n$e_point Responde *ACEPTO $lead_id* para comprar ya.\n$e_point O mira detalles aquí: $link";
            } else {
                $msg = "$e_bell *Nueva Cotización #$lead_id*\n\n$e_warn *Saldo Insuficiente* (Tienes $balance cr, requieres $cost cr).\n$e_memo $desc_short\n\n$e_point Recarga aquí: $shop_url";
            }
            
            sms_send_msg($phone, $msg);

            // Notificación Email de Respaldo
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($email, "Nueva Oportunidad #$lead_id", "<h3>Solicitud en {$lead->city}</h3><p>{$lead->requirement}</p><p><a href='$link'>Ver en Web</a></p>", $headers);
        }
    }
}

// 2. WEBHOOK INTERACCIÓN (Recepción de mensajes desde WhatsApp)
add_action('rest_api_init', function () {
    register_rest_route('smsenlinea/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => 'sms_handle_incoming_interaction',
        'permission_callback' => '__return_true',
    ]);
});

function sms_handle_incoming_interaction($req) {
    global $wpdb;
    $params = $req->get_params();

    // Emojis de respuesta
    $e_check = "\xE2\x9C\x85"; $e_gift = "\xF0\x9F\x8E\x81"; $e_party = "\xF0\x9F\x8E\x89"; 
    $e_user = "\xF0\x9F\x91\xA4"; $e_phone = "\xF0\x9F\x93\x9E"; $e_mail = "\xE2\x9C\x89"; 
    $e_lock = "\xF0\x9F\x94\x90"; $e_x = "\xE2\x9D\x8C"; $e_build = "\xF0\x9F\x8F\xA2"; $e_memo = "\xF0\x9F\x93\x9D";

    if(isset($params['type']) && $params['type'] == 'whatsapp') {
        $msg_body = trim(strtoupper($params['data']['message'])); 
        $phone_sender = str_replace('+', '', $params['data']['phone']); 
        
        // Evitar procesar el mismo mensaje dos veces (Idempotencia básica)
        $transient_key = 'sms_lock_' . md5($phone_sender . $msg_body);
        if (get_transient($transient_key)) return new WP_REST_Response('Ignored', 200);
        set_transient($transient_key, true, 60);

        // A. Verificación de Cuenta del Proveedor
        if ($msg_body === 'ACEPTO') {
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            if (!empty($users)) {
                $u = $users[0];
                update_user_meta($u->ID, 'sms_phone_status', 'verified');
                
                $bonus = (int) get_option('sms_welcome_bonus', 0);
                $given = get_user_meta($u->ID, '_sms_bonus_given', true);

                if ($bonus > 0 && !$given) {
                    $curr = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                    update_user_meta($u->ID, 'sms_wallet_balance', $curr + $bonus);
                    update_user_meta($u->ID, '_sms_bonus_given', 'yes');
                    sms_send_msg($phone_sender, "$e_check ¡Cuenta Verificada!\n$e_gift *Regalo:* Te cargamos *$bonus créditos* gratis.");
                } else {
                    sms_send_msg($phone_sender, "$e_check ¡Cuenta Verificada! Ahora recibirás alertas.");
                }
            }
        }
        // B. Compra vía WhatsApp (Ej: ACEPTO 123)
        elseif (preg_match('/^ACEPTO\s+(\d+)/i', $msg_body, $matches)) {
            $lead_id = intval($matches[1]);
            $users = get_users(['meta_query' => [['key' => 'billing_phone', 'value' => $phone_sender, 'compare' => 'LIKE']], 'number' => 1]);
            if(!empty($users)) {
                $u = $users[0];
                $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id=$lead_id");
                
                // VALIDAR SI EL PROVEEDOR TIENE APROBADO EL SERVICIO ANTES DE VENDER
                $approved_services = get_user_meta($u->ID, 'sms_approved_services', true) ?: [];
                
                if($lead && in_array($lead->service_page_id, $approved_services)) {
                    $already = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sms_lead_unlocks WHERE lead_id=$lead_id AND provider_user_id={$u->ID}");
                    if($already) {
                        // Ya lo compró antes, reenviar datos
                        $info = "$e_check *Datos del Cliente:*\n\n$e_user {$lead->client_name}\n$e_phone +{$lead->client_phone}\n$e_mail {$lead->client_email}";
                        sms_send_msg($phone_sender, $info);
                    } else {
                        // Intentar comprar
                        $bal = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                        if($bal >= $lead->cost_credits) {
                            update_user_meta($u->ID, 'sms_wallet_balance', $bal - $lead->cost_credits);
                            $wpdb->insert("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lead_id, 'provider_user_id' => $u->ID]);
                            
                            // Mensaje al Proveedor
                            $info = "$e_party *Compra Exitosa*\nNuevo saldo: ".($bal - $lead->cost_credits)."\n\nDatos:\n$e_build ".($lead->client_company?:'Particular')."\n$e_user {$lead->client_name}\n$e_phone +{$lead->client_phone}\n$e_mail {$lead->client_email}\n$e_memo {$lead->requirement}";
                            sms_send_msg($phone_sender, $info);

                            // NUEVO: Notificar al Cliente también (Opcional, si quieres replicar la lógica de unlock-logic aquí)
                            // Si deseas que la compra por WhatsApp TAMBIÉN avise al cliente, descomenta esto:
                            /*
                            $prov_name = $u->display_name;
                            $wa_link = "https://wa.me/" . str_replace('+', '', $phone_sender);
                            $msg_cliente = "👋 Hola {$lead->client_name}.\n✅ La empresa *{$prov_name}* ha aceptado tu solicitud.\n📞 Contacto: +{$phone_sender}\nWhatsApp: $wa_link";
                            sms_send_msg($lead->client_phone, $msg_cliente);
                            */

                        } else {
                            sms_send_msg($phone_sender, "$e_x Saldo insuficiente.");
                        }
                    }
                } else {
                    sms_send_msg($phone_sender, "$e_x No estás autorizado para este servicio o la cotización expiró.");
                }
            }
        }
        // C. Verificación de Cliente (Solicita Código)
        elseif (strpos($msg_body, 'WHATSAPP') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            $otp_key = 'sms_otp_lock_' . $phone_sender;
            if ($lead && !get_transient($otp_key)) {
                sms_send_msg($phone_sender, "$e_lock Tu código de verificación: *{$lead->verification_code}*");
                set_transient($otp_key, true, 45);
            }
        }
        // D. Verificación de Cliente (Pide Email)
        elseif (strpos($msg_body, 'EMAIL') !== false) {
            $lead = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE client_phone LIKE '%$phone_sender' AND is_verified = 0 ORDER BY created_at DESC LIMIT 1");
            $mail_key = 'sms_mail_lock_' . $phone_sender;
            if ($lead && is_email($lead->client_email) && !get_transient($mail_key)) {
                wp_mail($lead->client_email, "Código Verificación", "Código: <strong>{$lead->verification_code}</strong>", ['Content-Type: text/html; charset=UTF-8']);
                sms_send_msg($phone_sender, "$e_mail Código enviado a tu email.");
                set_transient($mail_key, true, 45); 
            }
        }
    }
    return new WP_REST_Response('OK', 200);
}
