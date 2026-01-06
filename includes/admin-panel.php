<?php
if (!defined('ABSPATH')) exit;

// ==========================================
// 1. MENU Y CONFIGURACIÓN
// ==========================================
add_action('admin_menu', function() {
    // Contar proveedores pendientes de revisión para la burbuja roja
    $pending_count = count(get_users(['meta_key' => 'sms_docs_status', 'meta_value' => 'pending']));
    
    $menu_label = 'Plataforma B2B';
    if ($pending_count > 0) {
        $menu_label .= " <span class='awaiting-mod count-$pending_count'><span class='pending-count'>$pending_count</span></span>";
    }
    
    add_menu_page('Plataforma B2B', $menu_label, 'manage_options', 'sms-b2b', 'sms_render_dashboard', 'dashicons-groups');
});

add_action('admin_init', function() {
    // API
    register_setting('sms_opts', 'sms_api_secret');
    register_setting('sms_opts', 'sms_account_id');
    register_setting('sms_opts', 'sms_admin_phone');
    register_setting('sms_opts', 'sms_webhook_secret'); 
    
    // Servicios
    register_setting('sms_opts', 'sms_active_service_pages'); 
    register_setting('sms_opts', 'sms_buttons_config'); 
    
    // Economía
    register_setting('sms_opts', 'sms_product_id'); 
    register_setting('sms_opts', 'sms_credits_qty'); 
    register_setting('sms_opts', 'sms_welcome_bonus'); 
});

// ==========================================
// 2. DASHBOARD (CONTENEDOR)
// ==========================================
function sms_render_dashboard() {
    $tab = $_GET['tab'] ?? 'leads';
    $pending_docs = count(get_users(['meta_key' => 'sms_docs_status', 'meta_value' => 'pending']));
    ?>
    <div class="wrap">
        <h1>Gestión B2B - Centro de Control</h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=sms-b2b&tab=leads" class="nav-tab <?php echo $tab=='leads'?'nav-tab-active':''; ?>">📥 Cotizaciones</a>
            <a href="?page=sms-b2b&tab=providers" class="nav-tab <?php echo $tab=='providers'?'nav-tab-active':''; ?>">
                👥 Proveedores 
                <?php if($pending_docs > 0) echo "<span class='update-plugins count-$pending_docs'><span class='plugin-count'>$pending_docs</span></span>"; ?>
            </a>
            <a href="?page=sms-b2b&tab=services" class="nav-tab <?php echo $tab=='services'?'nav-tab-active':''; ?>">🔘 Botones y Servicios</a>
            <a href="?page=sms-b2b&tab=requests" class="nav-tab <?php echo $tab=='requests'?'nav-tab-active':''; ?>">🔔 Solicitudes</a>
            <a href="?page=sms-b2b&tab=config" class="nav-tab <?php echo $tab=='config'?'nav-tab-active':''; ?>">⚙️ Configuración</a>
        </h2>
        <div style="background:#fff; padding:20px; border:1px solid #ccc; margin-top:10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <?php 
            if($tab == 'leads') sms_tab_leads();
            elseif($tab == 'providers') sms_tab_providers();
            elseif($tab == 'services') sms_tab_services();
            elseif($tab == 'requests') sms_tab_requests();
            else sms_tab_config();
            ?>
        </div>
    </div>
    <?php
}

// ==========================================
// 3. PESTAÑA LEADS (COTIZACIONES)
// ==========================================
function sms_tab_leads() {
    global $wpdb;
    
    if (isset($_POST['action_lead'])) {
        $lid = intval($_POST['lead_id']);
        
        // Guardar Texto
        if ($_POST['action_lead'] == 'save_edit') {
            $new_req = sanitize_textarea_field($_POST['edited_req']);
            $wpdb->update("{$wpdb->prefix}sms_leads", ['requirement' => $new_req], ['id' => $lid]);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Texto actualizado.</p></div>';
        }
        
        // Aprobar
        if ($_POST['action_lead'] == 'approve') {
            $cost = intval($_POST['lead_cost']);
            $new_req = sanitize_textarea_field($_POST['edited_req']);
            $wpdb->update("{$wpdb->prefix}sms_leads", 
                ['status' => 'approved', 'cost_credits' => $cost, 'requirement' => $new_req], 
                ['id' => $lid]
            );
            do_action('sms_notify_providers', $lid);
            echo '<div class="notice notice-success is-dismissible"><p>🚀 Cotización Publicada.</p></div>';
        }

        // Despublicar
        if ($_POST['action_lead'] == 'unapprove') {
            $wpdb->update("{$wpdb->prefix}sms_leads", ['status' => 'pending'], ['id' => $lid]);
            echo '<div class="notice notice-warning is-dismissible"><p>🚫 Cotización oculta (estado pendiente).</p></div>';
        }

        // Eliminar
        if ($_POST['action_lead'] == 'delete') {
            $wpdb->delete("{$wpdb->prefix}sms_leads", ['id' => $lid]);
            $wpdb->delete("{$wpdb->prefix}sms_lead_unlocks", ['lead_id' => $lid]);
            echo '<div class="notice notice-error is-dismissible"><p>🗑️ Cotización eliminada.</p></div>';
        }
    }

    $filter_status = $_GET['f_status'] ?? 'all';
    $where = "WHERE 1=1";
    if($filter_status == 'pending') $where .= " AND status = 'pending'";
    if($filter_status == 'approved') $where .= " AND status = 'approved'";

    $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_leads $where ORDER BY created_at DESC LIMIT 100");
    ?>
    
    <div class="tablenav top">
        <form method="get">
            <input type="hidden" name="page" value="sms-b2b">
            <input type="hidden" name="tab" value="leads">
            <select name="f_status">
                <option value="all" <?php selected($filter_status, 'all'); ?>>Todos</option>
                <option value="pending" <?php selected($filter_status, 'pending'); ?>>Pendientes</option>
                <option value="approved" <?php selected($filter_status, 'approved'); ?>>Publicados</option>
            </select>
            <input type="submit" class="button" value="Filtrar">
        </form>
    </div>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Datos Contacto (Admin)</th>
                <th>Servicio</th>
                <th style="width:35%;">Edición</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($leads as $l): 
                $page = get_post($l->service_page_id);
                $service_name = $page ? $page->post_title : '(General)';
                $ts = strtotime($l->created_at);
                $fecha_display = ($ts && date('Y', $ts) > 2000) ? date_i18n(get_option('date_format'), $ts) : '<span style="color:#999">(Sin fecha)</span>';
            ?>
            <tr>
                <td><?php echo $fecha_display; ?></td>
                <td>
                    <?php echo ($l->is_verified) ? '<span style="color:green;">✅ Verif.</span>' : '<span style="color:red;">❌ No Verif.</span>'; ?><br>
                    <strong><?php echo strtoupper($l->status); ?></strong>
                </td>
                <td style="background:#f0f6fc;">
                    <strong><?php echo esc_html($l->client_company); ?></strong><br>
                    👤 <?php echo esc_html($l->client_name); ?><br>
                    📞 <?php echo esc_html($l->client_phone); ?><br>
                    ✉️ <?php echo esc_html($l->client_email); ?>
                </td>
                <td><?php echo esc_html($service_name); ?></td>
                <td>
                    <form method="post" style="padding:5px;">
                        <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                        <textarea name="edited_req" style="width:100%; height:60px; font-size:12px; margin-bottom:5px;"><?php echo esc_textarea($l->requirement); ?></textarea>
                        <div style="display:flex; gap:5px; align-items:center;">
                            <button type="submit" name="action_lead" value="save_edit" class="button button-small">💾 Guardar Texto</button>
                            <?php if($l->status == 'pending'): ?>
                                <input type="number" name="lead_cost" value="10" style="width:45px; height:25px;" min="1">
                                <button type="submit" name="action_lead" value="approve" class="button button-primary button-small">Aprobar</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </td>
                <td style="text-align:center;">
                    <?php if($l->status == 'approved'): ?>
                        <div style="margin-bottom:5px;"><strong><?php echo $l->cost_credits; ?></strong> cr</div>
                        <form method="post"><input type="hidden" name="lead_id" value="<?php echo $l->id; ?>"><button type="submit" name="action_lead" value="unapprove" class="button button-small" style="color:orange;">🚫 Ocultar</button></form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('¿Eliminar definitivamente?');" style="margin-top:10px;">
                        <input type="hidden" name="lead_id" value="<?php echo $l->id; ?>">
                        <button type="submit" name="action_lead" value="delete" class="button button-link-delete" style="color:red; text-decoration:none;"><span class="dashicons dashicons-trash"></span></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

// ==========================================
// 4. PESTAÑA PROVEEDORES (COMPLETA)
// ==========================================
function sms_tab_providers() {
    global $wpdb;

    // ACCIONES ADMINISTRATIVAS
    if (isset($_POST['prov_action'])) {
        $uid = intval($_POST['user_id']);
        $prov_phone = get_user_meta($uid, 'billing_phone', true);
        
        // 1. Aprobar Documentos
        if ($_POST['prov_action'] == 'approve_docs') {
            update_user_meta($uid, 'sms_docs_status', 'verified');
            echo '<div class="notice notice-success"><p>✅ Documentos aprobados.</p></div>';
            
            // Notificar Proveedor
            if(function_exists('sms_send_msg') && $prov_phone) {
                sms_send_msg($prov_phone, "✅ Tus documentos han sido aprobados. Tu cuenta empresarial está verificada.");
            }
        }

        // 2. Borrar Documentos
        if ($_POST['prov_action'] == 'delete_docs') {
            delete_user_meta($uid, 'sms_company_docs');
            update_user_meta($uid, 'sms_docs_status', 'pending');
            echo '<div class="notice notice-error"><p>🗑️ Documentos eliminados.</p></div>';
        }

        // 3. Aprobar Servicios
        if ($_POST['prov_action'] == 'approve_services') {
            $requested = get_user_meta($uid, 'sms_requested_services', true) ?: [];
            update_user_meta($uid, 'sms_approved_services', $requested);
            echo '<div class="notice notice-success"><p>✅ Servicios vinculados.</p></div>';
            
            // Notificar
            if(function_exists('sms_send_msg') && $prov_phone) {
                sms_send_msg($prov_phone, "✅ Tus categorías de servicio han sido aprobadas. Ya puedes recibir cotizaciones.");
            }
        }

        // 4. Desvincular Servicios
        if ($_POST['prov_action'] == 'unlink_services') {
            update_user_meta($uid, 'sms_approved_services', []);
            echo '<div class="notice notice-warning"><p>🚫 Servicios desvinculados.</p></div>';
        }

        // 5. Saldo Manual
        if ($_POST['prov_action'] == 'manual_credit') {
            $amt = intval($_POST['credit_amount']);
            $curr = (int) get_user_meta($uid, 'sms_wallet_balance', true);
            update_user_meta($uid, 'sms_wallet_balance', $curr + $amt);
            echo '<div class="notice notice-success"><p>Saldo actualizado.</p></div>';
        }
    }

    // Obtener usuarios y ordenar pendientes primero
    $users = get_users(['orderby' => 'ID', 'order' => 'DESC']); 
    usort($users, function($a, $b) {
        $sa = get_user_meta($a->ID, 'sms_docs_status', true);
        $sb = get_user_meta($b->ID, 'sms_docs_status', true);
        if ($sa == 'pending' && $sb != 'pending') return -1;
        if ($sa != 'pending' && $sb == 'pending') return 1;
        return 0;
    });

    ?>
    <h3>Gestión Total de Proveedores</h3>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Proveedor</th>
                <th>Documentos & Estado</th>
                <th>Servicios (Solicitados vs Aprobados)</th>
                <th>Acciones & Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($users as $u): 
                $docs = get_user_meta($u->ID, 'sms_company_docs', true) ?: [];
                $doc_st = get_user_meta($u->ID, 'sms_docs_status', true);
                
                $req_servs = get_user_meta($u->ID, 'sms_requested_services', true) ?: [];
                $app_servs = get_user_meta($u->ID, 'sms_approved_services', true) ?: [];
                $balance = (int) get_user_meta($u->ID, 'sms_wallet_balance', true);
                
                $style = ($doc_st == 'pending') ? 'background:#fff9c4;' : '';
            ?>
            <tr style="<?php echo $style; ?>">
                <td>
                    <strong><?php echo $u->display_name; ?></strong><br>
                    <?php echo $u->user_email; ?><br>
                    📞 <?php echo get_user_meta($u->ID, 'billing_phone', true); ?>
                </td>
                <td>
                    <?php if($doc_st == 'verified'): ?>
                        <span style="color:green; font-weight:bold;">✅ Verificado</span>
                    <?php elseif($doc_st == 'pending'): ?>
                        <span style="color:#b38f00; font-weight:bold;">⚠️ Revisión Pendiente</span>
                    <?php else: ?>
                        <span style="color:red;">❌ Sin Validar</span>
                    <?php endif; ?>
                    
                    <?php if(!empty($docs)): ?>
                        <div style="margin-top:5px;">
                            <?php foreach($docs as $d): ?>
                                <a href="<?php echo $d; ?>" target="_blank" class="button button-small">📄 Ver Doc</a>
                            <?php endforeach; ?>
                        </div>
                        <form method="post" style="margin-top:5px;">
                            <input type="hidden" name="user_id" value="<?php echo $u->ID; ?>">
                            <?php if($doc_st != 'verified'): ?>
                                <button type="submit" name="prov_action" value="approve_docs" class="button button-primary button-small">Aprobar</button>
                            <?php endif; ?>
                            <button type="submit" name="prov_action" value="delete_docs" class="button button-link-delete" onclick="return confirm('¿Borrar documentos?');">Borrar</button>
                        </form>
                    <?php else: ?>
                        <br><small>No hay archivos.</small>
                    <?php endif; ?>
                </td>
                <td>
                    Solicitados: <strong><?php echo count($req_servs); ?></strong><br>
                    Aprobados: <strong style="color:green;"><?php echo count($app_servs); ?></strong>
                    
                    <?php if(count($req_servs) > count($app_servs)): ?>
                        <div style="color:orange; font-size:11px; margin-top:2px;">(Hay nuevos servicios solicitados)</div>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post">
                        <input type="hidden" name="user_id" value="<?php echo $u->ID; ?>">
                        
                        <div style="margin-bottom:8px;">
                            <button type="submit" name="prov_action" value="approve_services" class="button button-small" title="Aprobar servicios solicitados">✅ Vincular Servicios</button>
                            <button type="submit" name="prov_action" value="unlink_services" class="button button-small" style="color:orange; border-color:orange;" onclick="return confirm('¿Desvincular todo?');">🚫</button>
                        </div>
                        
                        <div style="border-top:1px solid #ddd; padding-top:5px; display:flex; align-items:center; gap:2px;">
                            <span style="font-weight:bold; margin-right:5px;"><?php echo $balance; ?> cr</span>
                            <input type="number" name="credit_amount" style="width:50px; height:25px;" placeholder="+/-">
                            <button type="submit" name="prov_action" value="manual_credit" class="button button-small">Ok</button>
                        </div>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

// ==========================================
// 5. PESTAÑA BOTONES Y SERVICIOS
// ==========================================
function sms_tab_services() {
    if(isset($_POST['save_config'])) {
        update_option('sms_active_service_pages', $_POST['active_pages'] ?? []);
        $new_buttons = [];
        if(isset($_POST['btn_labels'])) {
            $labels = $_POST['btn_labels']; $page_groups = $_POST['btn_pages_ids']; 
            for($i=0; $i < count($labels); $i++){
                if(!empty(trim($labels[$i]))){
                    $pgs = isset($page_groups[$i]) ? $page_groups[$i] : [];
                    if(!is_array($pgs)) $pgs = [];
                    $new_buttons[] = ['label' => sanitize_text_field($labels[$i]), 'pages' => array_map('intval', $pgs)];
                }
            }
        }
        update_option('sms_buttons_config', $new_buttons);
        echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
    }

    $all_pages = get_pages(['post_status'=>'publish']);
    $active = get_option('sms_active_service_pages', []); if(!is_array($active)) $active=[];
    $btns = get_option('sms_buttons_config', []); if(!is_array($btns)) $btns=[['label'=>'Cotizar','pages'=>[]]];
    ?>
    <form method="post">
        <div class="card" style="margin-bottom:20px; padding:15px;">
            <h3>1. Habilitar Servicios (Para Proveedores)</h3>
            <div style="height:200px; overflow-y:scroll; border:1px solid #ddd; padding:10px;">
                <?php if($all_pages): foreach($all_pages as $p): ?>
                <label style="display:block;">
                    <input type="checkbox" name="active_pages[]" value="<?php echo $p->ID; ?>" <?php checked(in_array($p->ID, $active)); ?>> 
                    <?php echo esc_html($p->post_title); ?>
                </label>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card" style="padding:15px;">
            <h3>2. Botones Flotantes</h3>
            <div id="buttons_wrapper">
                <?php foreach($btns as $idx => $btn): $btn_pages = (isset($btn['pages']) && is_array($btn['pages']))?$btn['pages']:[]; ?>
                <div class="btn-row" style="background:#f9f9f9; padding:10px; margin-bottom:10px; border:1px solid #ddd;">
                    <p>Texto: <input type="text" name="btn_labels[]" value="<?php echo esc_attr($btn['label']); ?>"></p>
                    <p>Páginas: <select name="btn_pages_ids[<?php echo $idx; ?>][]" multiple style="width:100%; height:100px;">
                        <?php if($all_pages): foreach($all_pages as $p): $sel=in_array($p->ID,$btn_pages)?'selected':''; ?>
                        <option value="<?php echo $p->ID; ?>" <?php echo $sel; ?>><?php echo esc_html($p->post_title); ?></option>
                        <?php endforeach; endif; ?>
                    </select></p>
                    <button type="button" class="button" onclick="this.parentElement.remove()">Eliminar</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="add_btn_row">➕ Añadir Botón</button>
            <hr><button type="submit" name="save_config" class="button button-primary">Guardar Todo</button>
        </div>
    </form>
    <script>document.getElementById('add_btn_row').addEventListener('click',function(){var w=document.getElementById('buttons_wrapper');if(w.children.length>0){var c=w.children[0].cloneNode(true);var i=w.children.length;c.querySelector('input').value='';c.querySelector('select').name='btn_pages_ids['+i+'][]';var o=c.querySelector('select').options;for(var k=0;k<o.length;k++)o[k].selected=false;w.appendChild(c);}else{alert('Guarda primero');}});</script>
    <?php
}

// ==========================================
// 6. PESTAÑA SOLICITUDES
// ==========================================
function sms_tab_requests() {
    global $wpdb;
    $reqs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sms_service_requests ORDER BY created_at DESC");
    ?>
    <h3>Solicitudes de Nuevas Categorías</h3>
    <table class="widefat striped">
        <thead><tr><th>Fecha</th><th>Proveedor</th><th>Servicio Solicitado</th></tr></thead>
        <tbody>
            <?php if($reqs): foreach($reqs as $r): $u = get_userdata($r->provider_user_id); ?>
            <tr>
                <td><?php echo $r->created_at; ?></td>
                <td><?php echo $u ? $u->display_name : 'ID '.$r->provider_user_id; ?></td>
                <td><strong><?php echo esc_html($r->requested_service); ?></strong></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

// ==========================================
// 7. PESTAÑA CONFIGURACIÓN
// ==========================================
function sms_tab_config() {
    $sec = get_option('sms_webhook_secret');
    $site_url = site_url();
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('sms_opts'); do_settings_sections('sms_opts'); ?>
        <table class="form-table">
            <tr><th>API Secret</th><td><input type="text" name="sms_api_secret" value="<?php echo get_option('sms_api_secret'); ?>" class="regular-text"></td></tr>
            <tr><th>Account ID</th><td><input type="text" name="sms_account_id" value="<?php echo get_option('sms_account_id'); ?>" class="regular-text"></td></tr>
            <tr><th>Teléfono Admin</th><td><input type="text" name="sms_admin_phone" value="<?php echo get_option('sms_admin_phone'); ?>" class="regular-text"></td></tr>
            <tr><th>Webhook Secret</th><td><input type="text" name="sms_webhook_secret" value="<?php echo $sec; ?>" class="regular-text"><p>URL: <code><?php echo $site_url; ?>/wp-json/smsenlinea/v1/webhook?secret=<?php echo $sec; ?></code></p></td></tr>
            <tr><td colspan="2"><hr><h3>Configuraciones</h3></td></tr>
            <tr><th>Bono Bienvenida</th><td><input type="number" name="sms_welcome_bonus" value="<?php echo get_option('sms_welcome_bonus', 0); ?>" class="small-text"></td></tr>
            <tr><th>ID Producto Recarga</th><td><input type="number" name="sms_product_id" value="<?php echo get_option('sms_product_id'); ?>" class="small-text"></td></tr>
            <tr><th>Créditos por Cantidad</th><td><input type="number" name="sms_credits_qty" value="<?php echo get_option('sms_credits_qty', 100); ?>" class="small-text"></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

// ==========================================
// 8. HOOKS AUXILIARES
// ==========================================
add_action('show_user_profile', 'sms_manual_credits_field');
add_action('edit_user_profile', 'sms_manual_credits_field');

function sms_manual_credits_field($user) {
    if (!current_user_can('manage_options')) return;
    $balance = (int) get_user_meta($user->ID, 'sms_wallet_balance', true);
    ?>
    <h3>💳 Gestión B2B</h3>
    <table class="form-table">
        <tr><th>Saldo Actual</th><td><input type="text" value="<?php echo $balance; ?>" disabled class="regular-text"> créditos</td></tr>
    </table>
    <?php
}

add_action('woocommerce_order_status_processing', 'sms_check_order_for_credits');
add_action('woocommerce_order_status_completed', 'sms_check_order_for_credits');

function sms_check_order_for_credits($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = $order->get_user_id();
    if (!$user_id) return; 
    if (get_post_meta($order_id, '_sms_credits_granted', true) == 'yes') return; 

    $target_pid = (int) get_option('sms_product_id');
    $credits_per_unit = (int) get_option('sms_credits_qty', 100);
    if (!$target_pid) return;

    $credits_to_add = 0; $found = false;
    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == $target_pid || $item->get_variation_id() == $target_pid) {
            $qty = $item->get_quantity();
            $credits_to_add += ($credits_per_unit * $qty);
            $found = true;
        }
    }
    if ($found && $credits_to_add > 0) {
        $curr = (int) get_user_meta($user_id, 'sms_wallet_balance', true);
        $new = $curr + $credits_to_add;
        update_user_meta($user_id, 'sms_wallet_balance', $new);
        update_post_meta($order_id, '_sms_credits_granted', 'yes');
        $order->add_order_note("✅ Sistema B2B: +$credits_to_add créditos. Nuevo saldo: $new");
    }
}