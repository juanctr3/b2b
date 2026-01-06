<?php
if (!defined('ABSPATH')) exit;

add_filter('woocommerce_account_menu_items', function($items) {
    $items['zona-proveedor'] = '🏭 Zona Proveedor';
    return $items;
});

add_action('init', function() { add_rewrite_endpoint('zona-proveedor', EP_ROOT | EP_PAGES); });

add_action('woocommerce_account_zona-proveedor_endpoint', function() {
    $uid = get_current_user_id();
    global $wpdb;

    // A. GUARDAR PERFIL
    if (isset($_POST['save_provider'])) {
        $code = sanitize_text_field($_POST['p_country_code']);
        $raw_phone = sanitize_text_field($_POST['p_phone_raw']);
        $new_phone = $code . $raw_phone; 
        
        update_user_meta($uid, 'billing_phone', $new_phone);
        update_user_meta($uid, 'sms_advisor_name', sanitize_text_field($_POST['p_advisor']));
        
        $requested_pages = $_POST['p_servs'] ?? [];
        update_user_meta($uid, 'sms_requested_services', $requested_pages);
        
        if(!get_user_meta($uid, 'sms_approved_services', true)) {
            update_user_meta($uid, 'sms_approved_services', []);
        }

        echo '<div class="woocommerce-message">✅ Perfil guardado.</div>';
    }

    // B. SOLICITUD SERVICIO
    if (isset($_POST['req_new_service'])) {
        $serv_name = sanitize_text_field($_POST['new_service_name']);
        if($serv_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sms_service_requests WHERE provider_user_id = %d AND requested_service = %s", $uid, $serv_name));
            if(!$exists) {
                $wpdb->insert("{$wpdb->prefix}sms_service_requests", ['provider_user_id' => $uid, 'requested_service' => $serv_name]);
                echo '<div class="woocommerce-message">✅ Solicitud enviada.</div>';
            }
        }
    }

    // C. CARGA DE DOCUMENTOS (CON NOTIFICACIÓN Y ANTI-DUPLICADOS)
    if (isset($_FILES['p_docs'])) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $files = $_FILES['p_docs'];
        $uploaded_count = 0;
        
        foreach ($files['name'] as $key => $value) {
            if ($files['name'][$key]) {
                $file = [
                    'name'     => $files['name'][$key],
                    'type'     => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error'    => $files['error'][$key],
                    'size'     => $files['size'][$key]
                ];
                $upload = wp_handle_upload($file, ['test_form' => false]);
                if (!isset($upload['error']) && isset($upload['url'])) {
                    $current_docs = get_user_meta($uid, 'sms_company_docs', true) ?: [];
                    $current_docs[] = $upload['url'];
                    update_user_meta($uid, 'sms_company_docs', $current_docs);
                    $uploaded_count++;
                }
            }
        }

        if ($uploaded_count > 0) {
            // 1. Cambiar estado
            update_user_meta($uid, 'sms_docs_status', 'pending');
            
            // 2. Notificar al Admin
            $admin_phone = get_option('sms_admin_phone');
            $admin_email = get_option('admin_email'); // O el que tengas configurado
            $prov_name = wp_get_current_user()->display_name;

            // WhatsApp Admin
            if(function_exists('sms_send_msg') && $admin_phone) {
                sms_send_msg($admin_phone, "📂 *Alerta Admin*\nEl proveedor *$prov_name* ha subido documentos para revisión.");
            }
            // Email Admin
            wp_mail($admin_email, "Documentos Pendientes - $prov_name", "El proveedor $prov_name ha subido documentos. Por favor revisa el panel de administración.");

            // 3. Redirección para evitar duplicados al recargar
            echo "<script>window.location.href = '" . wc_get_account_endpoint_url('zona-proveedor') . "?docs_uploaded=1';</script>";
            exit;
        }
    }

    // MENSAJE DE ÉXITO TRAS REDIRECCIÓN
    if(isset($_GET['docs_uploaded'])) {
        echo '<div class="woocommerce-message">✅ Documentos enviados correctamente. Notificamos al administrador.</div>';
    }

    // DATOS
    $active_pages_ids = get_option('sms_active_service_pages', []);
    $approved_servs = get_user_meta($uid, 'sms_approved_services', true) ?: [];
    $requested_servs = get_user_meta($uid, 'sms_requested_services', true) ?: [];
    $balance = (int) get_user_meta($uid, 'sms_wallet_balance', true);
    $advisor = get_user_meta($uid, 'sms_advisor_name', true);
    $docs_status = get_user_meta($uid, 'sms_docs_status', true);
    $docs_urls = get_user_meta($uid, 'sms_company_docs', true) ?: [];

    // LEADS
    $leads = [];
    if (!empty($approved_servs)) {
        $ids_str = implode(',', array_map('intval', $approved_servs));
        $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads WHERE service_page_id IN ($ids_str) AND status = 'approved' ORDER BY created_at DESC LIMIT 50");
    }
    
    // UI
    ?>
    <style>
        .sms-layout { display: flex; flex-wrap: wrap; gap: 25px; } .sms-col-main { flex: 2; min-width: 300px; } .sms-col-side { flex: 1; min-width: 280px; } .sms-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border:1px solid #eee; }
        .doc-list { margin-top:10px; font-size:12px; } .doc-item { background:#f9f9f9; padding:5px; margin-bottom:5px; border-radius:4px; display:flex; justify-content:space-between; }
    </style>

    <div class="sms-layout">
        <div class="sms-col-main">
            <h3>📋 Oportunidades</h3>
            <?php if(empty($approved_servs)): ?>
                <div class="sms-card">
                    <p>⚠️ Selecciona tus servicios y espera la validación del administrador.</p>
                </div>
            <?php elseif(empty($leads)): ?>
                <div class="sms-card"><p>No hay cotizaciones activas.</p></div>
            <?php else: ?>
                <div class="sms-card">
                <?php foreach($leads as $l): ?>
                    <div style="border-bottom:1px solid #eee; padding:10px 0;">
                        <strong><?php echo $l->client_company?:'Particular'; ?></strong><br>
                        <?php echo $l->city; ?> - <?php echo wp_trim_words($l->requirement, 10); ?><br>
                        <a href="<?php echo site_url('/oportunidad?lid='.$l->id); ?>" class="button button-small">Ver Detalles</a>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="sms-col-side">
            <div class="sms-card" style="background:#e8f0fe;">
                <h3>💰 Saldo: <?php echo $balance; ?></h3>
                <a href="/tienda" class="button">Recargar</a>
            </div>

            <div class="sms-card">
                <h4>📂 Documentos Empresa</h4>
                <p style="font-size:12px;">Sube RUT y Cámara de Comercio.</p>
                
                <?php if($docs_status == 'verified'): ?>
                    <div style="color:green; font-weight:bold; background:#d4edda; padding:10px; border-radius:5px;">✅ Documentación Aprobada</div>
                <?php elseif($docs_status == 'pending'): ?>
                    <div style="color:#856404; font-weight:bold; background:#fff3cd; padding:10px; border-radius:5px;">⏳ En Revisión por Admin</div>
                <?php else: ?>
                    <div style="color:red;">❌ Sin Validar</div>
                <?php endif; ?>

                <?php if($docs_status != 'verified'): ?>
                <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
                    <input type="file" name="p_docs[]" multiple accept=".pdf,.jpg,.png" style="font-size:12px;">
                    <button type="submit" class="button button-small" style="margin-top:5px;">Subir y Notificar</button>
                </form>
                <?php endif; ?>

                <div class="doc-list">
                    <?php foreach($docs_urls as $url): ?>
                        <div class="doc-item">
                            <a href="<?php echo $url; ?>" target="_blank">📄 Ver Documento</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sms-card">
                <h4>⚙️ Configuración</h4>
                <form method="post">
                    <label>Asesor:</label> <input type="text" name="p_advisor" value="<?php echo esc_attr($advisor); ?>" class="sms-input">
                    <label>Teléfono:</label> <input type="text" name="p_phone_raw" value="<?php echo substr(get_user_meta($uid, 'billing_phone', true), 3); ?>" class="sms-input">
                    <input type="hidden" name="p_country_code" value="+57">

                    <label>Servicios Solicitados:</label>
                    <div style="height:200px; overflow-y:scroll; border:1px solid #eee; padding:5px;">
                        <?php foreach($active_pages_ids as $pid): 
                            $p = get_post($pid); if(!$p) continue;
                            $is_requested = in_array($pid, $requested_servs);
                            $is_approved = in_array($pid, $approved_servs);
                        ?>
                        <label style="display:block; font-size:12px; margin-bottom:5px;">
                            <input type="checkbox" name="p_servs[]" value="<?php echo $pid; ?>" <?php checked($is_requested); ?>>
                            <?php echo $p->post_title; ?>
                            <?php if($is_approved): ?> <span style="color:green;">(Aprobado)</span>
                            <?php elseif($is_requested): ?> <span style="color:orange;">(Pendiente)</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_provider" class="button button-primary" style="width:100%; margin-top:10px;">Guardar</button>
                </form>
            </div>
        </div>
    </div>
    <?php
});