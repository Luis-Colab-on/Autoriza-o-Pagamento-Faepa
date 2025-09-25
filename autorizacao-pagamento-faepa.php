<?php
/**
 * Plugin Name: Autorização Pagamento FAEPA – Form 3 Abas
 * Description: Formulário em 3 passos (shortcode [apf_form]) + CPT de submissões. O dashboard está em includes/inbox.php.
 * Version: 0.1.0
 * Author: Você
 * License: GPLv2 or later
 */

if ( ! defined('ABSPATH') ) { exit; }

/* ====== CPT para armazenar as submissões ====== */
add_action('init', function () {
    register_post_type('apf_submission', [
        'labels' => [
            'name'          => 'Submissões FAEPA',
            'singular_name' => 'Submissão FAEPA',
            'menu_name'     => 'FAEPA Submissões',
        ],
        'public'      => false,
        'show_ui'     => true,
        'show_in_menu'=> true,
        'supports'    => ['title'],
    ]);
});

/* ====== Shortcode do formulário (3 abas) ====== */
add_shortcode('apf_form', function () {
    $out = '';

    // Processa envio
    if (!empty($_POST['apf_submit'])) {
        if (!isset($_POST['apf_nonce']) || !wp_verify_nonce($_POST['apf_nonce'], 'apf_form_nonce')) {
            return '<div style="color:#b00020">Falha de segurança. Recarregue a página.</div>';
        }

        // Helpers
        $get       = fn($k) => isset($_POST[$k]) ? wp_unslash($_POST[$k]) : '';
        $clean_txt = fn($v) => sanitize_text_field($v);
        $only_num  = fn($v) => preg_replace('/\D+/', '', $v);

        // Passo 1
        $nome_diretor   = $clean_txt($get('nome_diretor'));
        $num_controle   = $only_num($get('num_controle'));
        $tel_prestador  = $only_num($get('tel_prestador'));
        $email_prest    = sanitize_email($get('email_prest'));
        $num_doc_fiscal = $clean_txt($get('num_doc_fiscal'));
        $valor_bruto    = $get('valor');
        $valor_norm     = str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,\.]/', '', $valor_bruto));
        if ($valor_norm === '') $valor_norm = '0';

        $pessoa_tipo    = $get('pessoa_tipo') === 'pj' ? 'pj' : 'pf';
        $nome_empresa   = $clean_txt($get('nome_empresa'));
        $cnpj           = $only_num($get('cnpj'));
        $nome_prof      = $clean_txt($get('nome_prof'));
        $cpf            = $only_num($get('cpf'));

        // Passo 2
        $prest_contas   = $clean_txt($get('prest_contas'));
        $data_prest     = $clean_txt($get('data_prest'));
        $classificacao  = $clean_txt($get('classificacao'));
        $descricao      = sanitize_textarea_field($get('descricao'));
        $nome_curto     = $clean_txt($get('nome_curto'));
        $carga_horaria  = $only_num($get('carga_horaria'));

        // Passo 3
        $banco          = $clean_txt($get('banco'));
        $agencia        = $only_num($get('agencia'));
        $conta          = $only_num($get('conta'));

        // Salva
        $titulo = 'Solicitação - ' . ($pessoa_tipo==='pj' ? ($nome_empresa ?: 'PJ') : ($nome_prof ?: 'PF')) . ' - ' . current_time('Y-m-d H:i');
        $post_id = wp_insert_post([
            'post_type'   => 'apf_submission',
            'post_title'  => $titulo,
            'post_status' => 'publish',
        ]);

        if ($post_id && !is_wp_error($post_id)) {
            $meta = compact(
                // passo 1
                'nome_diretor','num_controle','tel_prestador','email_prest','num_doc_fiscal','valor_norm','pessoa_tipo','nome_empresa','cnpj','nome_prof','cpf',
                // passo 2
                'prest_contas','data_prest','classificacao','descricao','nome_curto','carga_horaria',
                // passo 3
                'banco','agencia','conta'
            );
            // grava com prefixo apf_
            foreach ($meta as $k => $v) {
                $key = $k === 'valor_norm' ? 'valor' : $k;
                update_post_meta($post_id, 'apf_'.$key, $v);
            }
            $out .= '<div style="padding:12px;border:1px solid #cde;border-radius:8px;background:#f7fbff;margin-bottom:16px">Formulário enviado com sucesso.</div>';
        } else {
            $out .= '<div style="padding:12px;border:1px solid #f3c;border-radius:8px;background:#fff5f8;margin-bottom:16px;color:#b00020">Não foi possível enviar agora.</div>';
        }
    }

    ob_start(); ?>
    <div class="apf-card">
      <h2>Autorização de Pagamento</h2>

      <form method="post" id="apfForm" novalidate>
        <?php wp_nonce_field('apf_form_nonce','apf_nonce'); ?>
        <input type="hidden" name="apf_submit" value="1">

        <div class="apf-steps">
          <div class="apf-step is-active">1. Informações do Pagamento</div>
          <div class="apf-step">2. Prestação do serviço</div>
          <div class="apf-step">3. Dados para pagamento</div>
        </div>

        <!-- STEP 1 -->
        <section class="apf-pane is-active" data-step="1">
          <div class="apf-grid">
            <label>Nome Completo Diretor Executivo
              <input type="text" name="nome_diretor" required>
            </label>

            <label>Número de Controle Secretaria *
              <input type="text" name="num_controle" inputmode="numeric" maxlength="20" required>
            </label>

            <label>Telefone do Prestador *
              <input type="tel" name="tel_prestador" placeholder="(00) 00000-0000" required>
            </label>

            <label>E-mail do Prestador *
              <input type="email" name="email_prest" required>
            </label>

            <label>Número do Documento Fiscal
              <input type="text" name="num_doc_fiscal">
            </label>

            <label>Valor (R$) *
              <input type="text" name="valor" placeholder="0,00" required>
            </label>
          </div>

          <fieldset class="apf-row">
            <legend>Pessoa</legend>
            <label class="apf-radio">
              <input type="radio" name="pessoa_tipo" value="pj" checked> Pessoa Jurídica (PJ)
            </label>
            <label class="apf-radio">
              <input type="radio" name="pessoa_tipo" value="pf"> Pessoa Física (PF)
            </label>
          </fieldset>

          <div class="apf-grid" id="apfPJ">
            <label>Nome da Empresa
              <input type="text" name="nome_empresa">
            </label>
            <label>CNPJ
              <input type="text" name="cnpj" placeholder="00.000.000/0000-00">
            </label>
          </div>

          <div class="apf-grid" id="apfPF" style="display:none">
            <label>Nome do Profissional
              <input type="text" name="nome_prof">
            </label>
            <label>CPF
              <input type="text" name="cpf" placeholder="000.000.000-00">
            </label>
          </div>

          <div class="apf-actions">
            <button type="button" class="apf-next">Próximo</button>
          </div>
        </section>

        <!-- STEP 2 -->
        <section class="apf-pane" data-step="2">
          <div class="apf-grid">
            <label>Prestação de contas
              <input type="text" name="prest_contas">
            </label>

            <label>Data de prestação de serviço
              <input type="date" name="data_prest">
            </label>

            <label>Classificação
              <input type="text" name="classificacao">
            </label>

            <label>Descrição do serviço ou material
              <textarea name="descricao" rows="3"></textarea>
            </label>

            <label>Nome curto do curso
              <input type="text" name="nome_curto">
            </label>

            <label>Carga horária do curso
              <input type="text" name="carga_horaria" inputmode="numeric" maxlength="5" placeholder="ex: 8">
            </label>
          </div>

          <div class="apf-actions">
            <button type="button" class="apf-prev">Voltar</button>
            <button type="button" class="apf-next">Próximo</button>
          </div>
        </section>

        <!-- STEP 3 -->
        <section class="apf-pane" data-step="3">
          <div class="apf-grid">
            <label>Banco
              <input type="text" name="banco">
            </label>
            <label>Agência
              <input type="text" name="agencia" inputmode="numeric" maxlength="10" placeholder="0000-0">
            </label>
            <label>Conta Corrente
              <input type="text" name="conta" inputmode="numeric" maxlength="20" placeholder="000000-0">
            </label>
          </div>

          <div class="apf-actions">
            <button type="button" class="apf-prev">Voltar</button>
            <button type="submit" class="apf-submit">Enviar</button>
          </div>
        </section>
      </form>
    </div>

    <style>
      .apf-card{max-width:640px;margin:16px auto;padding:20px 22px;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(0,0,0,.5);border: 1px solid #8d8d8d;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial}
      .apf-card h2{margin:0 0 14px;font-size:22px;text-align:center}
      .apf-steps{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:10px 0 16px}
      .apf-step{background:#f2f4f7;border:1px solid #e6e9ef;border-radius:10px;padding:10px 12px;font-size:12.5px;text-align:center;color:#475467}
      .apf-step.is-active{background:#e8f1ff;border-color:#bcd4ff;color:#1849a9;font-weight:600}
      .apf-pane{display:none}
      .apf-pane.is-active{display:block}
      .apf-grid{display:grid;grid-template-columns:1fr;gap:12px}
      .apf-grid label{display:flex;flex-direction:column;font-size:13px;color:#344054}
      .apf-grid input,.apf-grid textarea{border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px}
      .apf-row{display:flex;gap:18px;border:none;margin:10px 0 0;padding:0}
      .apf-radio{display:flex;align-items:center;gap:6px;font-size:14px}
      .apf-actions{display:flex;justify-content:space-between;margin-top:16px}
      .apf-actions button{background:#1f6feb;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
      .apf-actions .apf-prev{background:#a0a7b4}
      @media(min-width:640px){ .apf-grid{grid-template-columns:1fr 1fr} }
    </style>

    <script>
    (function(){
      const form = document.getElementById('apfForm');
      const panes = Array.from(form.querySelectorAll('.apf-pane'));
      const steps = Array.from(form.querySelectorAll('.apf-step'));
      let current = 0;

      function showStep(i){
        panes.forEach((p,idx)=>p.classList.toggle('is-active', idx===i));
        steps.forEach((s,idx)=>s.classList.toggle('is-active', idx===i));
        current = i;
        window.scrollTo({top: form.getBoundingClientRect().top + window.scrollY - 20, behavior:'smooth'});
      }

      form.addEventListener('click', function(e){
        if(e.target.classList.contains('apf-next')){
          if ( validatePane(panes[current]) ) showStep(Math.min(current+1, panes.length-1));
        }
        if(e.target.classList.contains('apf-prev')){
          showStep(Math.max(current-1, 0));
        }
      });

      function validatePane(pane){
        const req = Array.from(pane.querySelectorAll('[required]'));
        for(const el of req){
          if(!el.value.trim()){ el.focus(); el.reportValidity && el.reportValidity(); return false; }
        }
        return true;
      }

      // PF/PJ
      const radios = form.querySelectorAll('input[name="pessoa_tipo"]');
      const boxPF = document.getElementById('apfPF');
      const boxPJ = document.getElementById('apfPJ');
      function togglePessoa(){
        const v = form.querySelector('input[name="pessoa_tipo"]:checked').value;
        const pf = v === 'pf';
        boxPF.style.display = pf ? '' : 'none';
        boxPJ.style.display = pf ? 'none' : '';
        form.querySelector('input[name="nome_prof"]').required = pf;
        form.querySelector('input[name="cpf"]').required = pf;
        form.querySelector('input[name="nome_empresa"]').required = !pf;
        form.querySelector('input[name="cnpj"]').required = !pf;
      }
      radios.forEach(r => r.addEventListener('change', togglePessoa));
      togglePessoa();

      // Máscaras
      const onlyNum = v => v.replace(/\D+/g,'');
      function maskPhone(el){
        let v = onlyNum(el.value).slice(0,11);
        if(v.length>10){ el.value = v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3'); }
        else if(v.length>6){ el.value = v.replace(/(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3'); }
        else if(v.length>2){ el.value = v.replace(/(\d{2})(\d{0,5})/,'($1) $2'); }
        else { el.value = v; }
      }
      function maskCPF(el){
        let v = onlyNum(el.value).slice(0,11);
        if(v.length>9){ el.value = v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/,'$1.$2.$3-$4'); }
        else if(v.length>6){ el.value = v.replace(/(\d{3})(\d{3})(\d{0,3})/,'$1.$2.$3'); }
        else if(v.length>3){ el.value = v.replace(/(\d{3})(\d{0,3})/,'$1.$2'); }
        else { el.value = v; }
      }
      function maskCNPJ(el){
        let v = onlyNum(el.value).slice(0,14);
        if(v.length>12){ el.value = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/,'$1.$2.$3/$4-$5'); }
        else if(v.length>8){ el.value = v.replace(/(\d{2})(\d{3})(\d{3})(\d{0,4})/,'$1.$2.$3/$4'); }
        else if(v.length>5){ el.value = v.replace(/(\d{2})(\d{3})(\d{0,3})/,'$1.$2.$3'); }
        else if(v.length>2){ el.value = v.replace(/(\d{2})(\d{0,3})/,'$1.$2'); }
        else { el.value = v; }
      }
      function maskInt(el, max){ el.value = onlyNum(el.value).slice(0,max||20); }
      function maskMoney(el){
        let v = onlyNum(el.value);
        if(!v) { el.value = ''; return; }
        v = (parseInt(v,10)).toString();
        while(v.length < 3) v = '0'+v;
        const cents = v.slice(-2);
        const ints  = v.slice(0,-2).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        el.value = ints + ',' + cents;
      }

      form.querySelector('input[name="tel_prestador"]').addEventListener('input', e => maskPhone(e.target));
      form.querySelector('input[name="cpf"]').addEventListener('input', e => maskCPF(e.target));
      form.querySelector('input[name="cnpj"]').addEventListener('input', e => maskCNPJ(e.target));
      form.querySelector('input[name="num_controle"]').addEventListener('input', e => maskInt(e.target, 20));
      form.querySelector('input[name="carga_horaria"]').addEventListener('input', e => maskInt(e.target, 5));
      form.querySelector('input[name="agencia"]').addEventListener('input', e => maskInt(e.target, 10));
      form.querySelector('input[name="conta"]').addEventListener('input', e => maskInt(e.target, 20));
      form.querySelector('input[name="valor"]').addEventListener('input', e => maskMoney(e.target));

      showStep(0);
    })();
    </script>
    <?php
    $out .= ob_get_clean();
    return $out;
});

require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/admin-meta.php';
