<?php
if (!defined('ABSPATH')) exit;

add_action('wp_footer', 'sms_render_frontend');

function sms_render_frontend() {
    $current_page_id = get_queried_object_id();
    if (!$current_page_id) return;

    $buttons_config = get_option('sms_buttons_config', []);
    $active_btn = null;
    
    if (!empty($buttons_config) && is_array($buttons_config)) {
        foreach ($buttons_config as $btn) {
            if (isset($btn['pages']) && is_array($btn['pages']) && in_array($current_page_id, $btn['pages'])) {
                $active_btn = $btn; break;
            }
        }
    }
    if (!$active_btn) return;

    $users = get_users();
    $provider_count = 0;
    foreach ($users as $u) {
        $subs = get_user_meta($u->ID, 'sms_approved_services', true); // Contar solo aprobados
        if (is_array($subs) && in_array($current_page_id, $subs)) $provider_count++;
    }

    ?>
    <style>
        .sms-fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 9999; }
        .sms-fab { background: #25d366; color: #fff; padding: 12px 25px; border-radius: 50px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 10px; font-weight: bold; }
        .sms-counter-badge { background: #333; color: #fff; font-size: 11px; padding: 5px 10px; border-radius: 10px; margin-bottom: 5px; text-align: right; }
        .sms-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; justify-content: center; align-items: center; }
        .sms-modal-box { background: #fff; width: 90%; max-width: 450px; padding: 25px; border-radius: 12px; position: relative; max-height: 90vh; overflow-y: auto; }
        .sms-input { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .sms-btn-submit { width: 100%; padding: 12px; background: #007cba; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 5px; }
        
        /* Radio Buttons */
        .sms-type-selector { display: flex; gap: 15px; margin-bottom: 15px; }
        .sms-type-selector label { display: flex; align-items: center; gap: 5px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .sms-warning-box { background: #fff3cd; color: #856404; padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 15px; display: none; border: 1px solid #ffeeba; }
    </style>

    <div class="sms-fab-container" onclick="openSmsModal()">
        <?php if($provider_count > 0): ?>
            <div class="sms-counter-badge">&#127970; <?php echo $provider_count; ?> Empresas Disponibles</div>
        <?php endif; ?>
        <div class="sms-fab">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
            <span><?php echo esc_html($active_btn['label']); ?></span>
        </div>
    </div>

    <div id="smsModal" class="sms-modal-overlay">
        <div class="sms-modal-box">
            <span onclick="event.stopPropagation(); closeSmsModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer;">&times;</span>
            
            <div id="smsStep1">
                <h3>Solicitar Cotizaci&oacute;n</h3>
                
                <form id="smsLeadForm">
                    <input type="hidden" name="action" value="sms_submit_lead_step1">
                    <input type="hidden" name="page_id" value="<?php echo $current_page_id; ?>">
                    
                    <div class="sms-type-selector">
                        <label><input type="radio" name="client_type" value="company" checked onchange="toggleClientType()"> Soy Empresa</label>
                        <label><input type="radio" name="client_type" value="person" onchange="toggleClientType()"> Persona Natural</label>
                    </div>

                    <div id="personWarning" class="sms-warning-box">
                        &#9888; Nota: Algunas empresas B2B se abstienen de cotizar a personas naturales o servicios residenciales.
                    </div>

                    <select name="country" class="sms-input" id="smsCountrySel" onchange="updateSmsCode()">
                        <option value="Colombia" data-code="+57">???? Colombia (+57)</option>
                        <option value="Mexico" data-code="+52">???? M&eacute;xico (+52)</option>
                        <option value="Peru" data-code="+51">???? Per&uacute; (+51)</option>
                        <option value="Espana" data-code="+34">???? Espa&ntilde;a (+34)</option>
                        <option value="USA" data-code="+1">???? USA (+1)</option>
                    </select>

                    <input type="text" name="city" placeholder="Ciudad" class="sms-input" required>
                    
                    <div id="companyField">
                        <input type="text" name="company" placeholder="Nombre de tu Empresa" class="sms-input">
                    </div>

                    <input type="text" name="name" placeholder="Tu Nombre de Contacto" class="sms-input" required>
                    
                    <div style="display:flex; gap:5px;">
                        <input type="text" id="smsPhoneCode" value="+57" readonly style="width:60px; background:#f0f0f0; text-align:center;" class="sms-input">
                        <input type="number" name="phone_raw" placeholder="3001234567" class="sms-input" required>
                    </div>
                    
                    <input type="email" name="email" placeholder="Email Corporativo" class="sms-input" required>
                    <textarea name="req" rows="3" placeholder="Describe tu requerimiento..." class="sms-input" required></textarea>
                    
                    <button type="submit" class="sms-btn-submit" id="btnStep1">Continuar</button>
                </form>
            </div>

            <div id="smsStepWaitInteraction" style="display:none; text-align:center;">
                <h3 style="color:#007cba;">&#128172; Acci&oacute;n Requerida</h3>
                <p>Te hemos enviado un mensaje a WhatsApp. Responde <strong>"WHATSAPP"</strong> para recibir el c&oacute;digo.</p>
                <button onclick="goToStep2()" class="sms-btn-submit" style="background:#fff; color:#007cba; border:1px solid #007cba;">Ya respond&iacute;</button>
            </div>

            <div id="smsStep2" style="display:none; text-align:center;">
                <h3>?? C&oacute;digo</h3>
                <input type="text" id="otpInput" class="sms-input" placeholder="0000" style="text-align:center; font-size:24px;">
                <input type="hidden" id="tempLeadId">
                <button onclick="verifyOtp()" class="sms-btn-submit">Verificar</button>
                <p id="otpError" style="color:red;"></p>
            </div>

            <div id="smsStep3" style="display:none; text-align:center;">
                <h2 style="color:green;">?</h2>
                <h3>&iexcl;Enviado!</h3>
                <button onclick="closeSmsModal()" class="sms-btn-submit">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        function openSmsModal(){ document.getElementById('smsModal').style.display='flex'; }
        function closeSmsModal(){ document.getElementById('smsModal').style.display='none'; }
        function goToStep2(){ document.getElementById('smsStepWaitInteraction').style.display='none'; document.getElementById('smsStep2').style.display='block'; }
        function updateSmsCode() { document.getElementById('smsPhoneCode').value = document.getElementById('smsCountrySel').options[document.getElementById('smsCountrySel').selectedIndex].getAttribute('data-code'); }

        function toggleClientType() {
            var type = document.querySelector('input[name="client_type"]:checked').value;
            if(type === 'person') {
                document.getElementById('companyField').style.display = 'none';
                document.getElementById('personWarning').style.display = 'block';
            } else {
                document.getElementById('companyField').style.display = 'block';
                document.getElementById('personWarning').style.display = 'none';
            }
        }

        document.getElementById('smsLeadForm').addEventListener('submit', function(e){
            e.preventDefault();
            var btn = document.getElementById('btnStep1'); btn.disabled = true; btn.innerHTML = 'Enviando...';
            var fd = new FormData(this);
            fd.append('phone', document.getElementById('smsPhoneCode').value + fd.get('phone_raw'));

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
                if(d.success){
                    document.getElementById('smsStep1').style.display='none';
                    document.getElementById('smsStepWaitInteraction').style.display='block';
                    document.getElementById('tempLeadId').value = d.data.lead_id;
                } else { alert(d.data); btn.disabled=false; btn.innerHTML='Continuar'; }
            });
        });

        function verifyOtp(){
            var fd = new FormData();
            fd.append('action', 'sms_verify_otp');
            fd.append('lead_id', document.getElementById('tempLeadId').value);
            fd.append('code', document.getElementById('otpInput').value);
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
                if(d.success){
                    document.getElementById('smsStep2').style.display='none';
                    document.getElementById('smsStep3').style.display='block';
                } else { document.getElementById('otpError').innerText = 'C¨®digo incorrecto'; }
            });
        }
    </script>
    <?php
}

// AJAX (Igual que antes)
add_action('wp_ajax_sms_submit_lead_step1', 'sms_handle_step1');
add_action('wp_ajax_nopriv_sms_submit_lead_step1', 'sms_handle_step1');
function sms_handle_step1() {
    global $wpdb;
    $otp = rand(1000, 9999);
    $full_phone = sanitize_text_field($_POST['phone']);
    // Guardar si es persona o empresa en el nombre de empresa
    $company = ($_POST['client_type'] == 'person') ? '(Persona Natural)' : sanitize_text_field($_POST['company']);

    $data = [
        'country' => sanitize_text_field($_POST['country']),
        'city' => sanitize_text_field($_POST['city']),
        'client_company' => $company,
        'client_name' => sanitize_text_field($_POST['name']),
        'client_phone' => $full_phone,
        'client_email' => sanitize_email($_POST['email']),
        'service_page_id' => intval($_POST['page_id']),
        'requirement' => sanitize_textarea_field($_POST['req']),
        'verification_code' => $otp,
        'status' => 'pending',
        'created_at' => current_time('mysql')
    ];
    $wpdb->insert("{$wpdb->prefix}sms_leads", $data);
    $lid = $wpdb->insert_id;
    
    if(function_exists('sms_send_msg')) {
        $msg = "\xF0\x9F\x91\x8B Hola. Para recibir tu c\xC3\xB3digo de verificaci\xC3\xB3n, responde:\n\n\xF0\x9F\x91\x89 *WHATSAPP* (por este medio)\n\xF0\x9F\x91\x89 *EMAIL* (por correo)";
        sms_send_msg($full_phone, $msg);
    }
    wp_send_json_success(['lead_id' => $lid]);
}

add_action('wp_ajax_sms_verify_otp', 'sms_handle_step2');
add_action('wp_ajax_nopriv_sms_verify_otp', 'sms_handle_step2');
function sms_handle_step2() {
    global $wpdb;
    $lid = intval($_POST['lead_id']);
    $code = sanitize_text_field($_POST['code']);
    $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sms_leads WHERE id = $lid");
    if ($row && $row->verification_code == $code) {
        $wpdb->update("{$wpdb->prefix}sms_leads", ['is_verified' => 1], ['id' => $lid]);
        $admin_phone = get_option('sms_admin_phone');
        if($admin_phone && function_exists('sms_send_msg')) sms_send_msg($admin_phone, "\xF0\x9F\x94\x94 Nueva cotizaci\xC3\xB3n #$lid verificada.");
        wp_send_json_success();
    } else { wp_send_json_error(); }
}