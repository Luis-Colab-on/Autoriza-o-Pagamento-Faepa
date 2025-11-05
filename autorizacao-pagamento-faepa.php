<?php
/**
 * Plugin Name: Autorização Pagamento FAEPA – Form 3 Abas
 * Description: Formulário em 3 passos (shortcode [apf_form]) + portal do prestador + dashboard [apf_inbox].
 * Version: 0.4.0
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
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'supports'     => ['title'],
    ]);
});

/** Retorna a submissão mais recente do usuário (ou 0 se não houver). */
function apf_get_user_submission_id( $user_id, $email_prest = '' ){
    $q = new WP_Query([
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'author'         => (int) $user_id,
        'fields'         => 'ids',
    ]);
    if ( $q->have_posts() && ! empty($q->posts[0]) ) return (int) $q->posts[0];

    if ( $email_prest ) {
        $q2 = new WP_Query([
            'post_type'      => 'apf_submission',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [[
                'key'     => 'apf_email_prest',
                'value'   => $email_prest,
                'compare' => 'LIKE',
            ]],
            'fields'         => 'ids',
        ]);
        if ( $q2->have_posts() && ! empty($q2->posts[0]) ) return (int) $q2->posts[0];
    }
    return 0;
}

if ( ! function_exists( 'apf_render_login_card' ) ) {
    /**
     * Renderiza um cartão de login elegante reutilizado pelo formulário público e portal.
     */
    function apf_render_login_card( $args = array() ) {
        if ( ! function_exists( 'wp_login_form' ) ) {
            return '<p>Faça login para continuar.</p>';
        }

        $defaults = array(
            'redirect'       => isset( $_SERVER['REQUEST_URI'] ) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url(),
            'title'          => 'Acesse sua conta',
            'description'    => 'Use sua conta FAEPA ou cadastre-se rapidamente para continuar o envio.',
        );
        $args = wp_parse_args( $args, $defaults );

        $form = wp_login_form( array(
            'echo'           => false,
            'redirect'       => $args['redirect'],
            'remember'       => true,
            'label_username' => 'Usuário ou e-mail',
            'label_password' => 'Senha',
            'label_remember' => 'Manter conectado',
            'label_log_in'   => 'Entrar',
            'form_id'        => 'apf-login-form',
        ) );

        static $printed_css = false;
        $css = '';
        if ( ! $printed_css ) {
            $printed_css = true;
            $css = '
            <style>
              .apf-login-card{max-width:420px;margin:48px auto;border-radius:20px;background:#ffffff;box-shadow: 0 18px 35px rgb(0 0 0 / 65%);;color:#0f172a;padding:36px;}
              .apf-login-card__inner{padding:0;}
              .apf-login-card__badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;background:rgba(25,118,210,.12);color:#0d47a1;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;}
              .apf-login-card h2{margin:0;font-size:24px;color:#0f172a;}
              .apf-login-card p{margin:8px 0 0;font-size:13.5px;color:#475467;}
              .login-remember {width: 13px; white-space: nowrap;}
              #apf-login-form{margin-top:24px;display:grid;gap:18px;}
              #apf-login-form p{margin:0;}
              #apf-login-form label{display:flex;font-size:13px;font-weight:600;color:#1d2939;gap:6px;}
              #apf-login-form input[type="text"],#apf-login-form input[type="password"]{border:1px solid #d0d5dd;border-radius:12px;padding:12px 14px;font-size:14px;width:100%;transition:border-color .18s ease, box-shadow .18s ease;}
              #apf-login-form input[type="text"]:focus,#apf-login-form input[type="password"]:focus{border-color:#1976d2;box-shadow:0 0 0 4px rgba(25,118,210,.18);outline:none;}
              #apf-login-form .forgetmenot{display:flex;align-items:center;gap:8px;font-size:13px;color:#475467;margin-top:4px;}
              #apf-login-form .forgetmenot label{flex-direction:row;align-items:center;font-size:13px;font-weight:500;gap:8px;margin:0;}
              #apf-login-form .forgetmenot input{margin:0;}
              #apf-login-form .submit{margin:0;}
              #apf-login-form .submit input{width:100%;border:none;border-radius:12px;padding:12px 16px;font-size:15px;font-weight:600;background:#1976d2;color:#fff;cursor:pointer;transition:background .2s ease, box-shadow .2s ease;}
              #apf-login-form .submit input:hover{background:#0d47a1;box-shadow:0 12px 24px rgba(13,71,161,.28);}
              .apf-login-card__footer{margin-top:16px;display:flex;justify-content:space-between;gap:12px;font-size:13px;}
              .apf-login-card__footer a{color:#1976d2;text-decoration:none;font-weight:600;}
              .apf-login-card__footer a:hover{text-decoration:underline;}
              @media(max-width:540px){
                .apf-login-card{margin:32px 16px;padding:30px 26px;}
                .apf-login-card__inner{padding:28px 22px;}
                .apf-login-card__footer{flex-direction:column;align-items:flex-start;}
              }
            </style>';
        }

        $register_url = esc_url( wp_registration_url() );
        $lost_url     = esc_url( wp_lostpassword_url() );

        return $css . '
        <div class="apf-login-card" aria-live="polite">
          <div class="apf-login-card__inner">
            <span class="apf-login-card__badge">Área Restrita</span>
            <h2>' . esc_html( $args['title'] ) . '</h2>
            <p>' . esc_html( $args['description'] ) . '</p>
            ' . $form . '
            <div class="apf-login-card__footer">
              <a href="' . $register_url . '">Criar uma conta</a>
              <a href="' . $lost_url . '">Esqueci minha senha</a>
            </div>
          </div>
        </div>';
    }
}

/* ====== Shortcode do formulário (3 abas) ====== */
add_shortcode('apf_form', function () {
    if ( ! is_user_logged_in() ) {
        return apf_render_login_card();
    }

    $out = '';
    $nome_diretor = '';

    $apf_directors = get_option('apf_directors', []);
    if ( ! is_array($apf_directors) ) {
        $apf_directors = [];
    }
    if ( ! empty($apf_directors) ) {
        usort($apf_directors, function( $a, $b ){
            $course_a = $a['course'] ?? '';
            $course_b = $b['course'] ?? '';
            $by_course = strcasecmp($course_a, $course_b);
            if ( $by_course !== 0 ) {
                return $by_course;
            }
            return strcasecmp($a['director'] ?? '', $b['director'] ?? '');
        });
    }
    $apf_director_names = [];
    foreach ( $apf_directors as $entry ) {
        if ( isset($entry['director']) ) {
            $name = trim((string) $entry['director']);
            if ( $name !== '' ) {
                $apf_director_names[$name] = true;
            }
        }
    }

    // Processa envio (AGORA: sempre cria um novo registro)
    if ( ! empty($_POST['apf_submit']) ) {
        if ( ! isset($_POST['apf_nonce']) || ! wp_verify_nonce($_POST['apf_nonce'], 'apf_form_nonce') ) {
            return '<div style="color:#b00020">Falha de segurança. Recarregue a página.</div>';
        }

        // Helpers
        $only_num = function($v){ return preg_replace('/\D+/', '', (string)$v); };
        $clean    = function($v){ return sanitize_text_field($v); };

        // ====== CAPTURA E SANITIZA ======
        // Passo 1
        $nome_diretor   = $clean( wp_unslash($_POST['nome_diretor']   ?? '') );
        $num_controle   = $only_num( wp_unslash($_POST['num_controle']   ?? '') );
        $tel_prestador  = $only_num( wp_unslash($_POST['tel_prestador']  ?? '') );
        $email_prest    = sanitize_email( wp_unslash($_POST['email_prest'] ?? '') );
        $num_doc_fiscal = $clean( wp_unslash($_POST['num_doc_fiscal'] ?? '') );
        $valor_bruto    = wp_unslash($_POST['valor'] ?? '');

        // normaliza 1.234,56 -> 1234.56
        $valor_norm = preg_replace('/[^\d,\.]/', '', $valor_bruto);
        $valor_norm = str_replace(['.', ','], ['', '.'], $valor_norm);
        if ($valor_norm === '') $valor_norm = '0';

        $pessoa_tipo  = (isset($_POST['pessoa_tipo']) && $_POST['pessoa_tipo']==='pf') ? 'pf' : 'pj';
        $nome_empresa = $clean( wp_unslash($_POST['nome_empresa'] ?? '') );
        $cnpj         = $only_num( wp_unslash($_POST['cnpj'] ?? '') );
        $nome_prof    = $clean( wp_unslash($_POST['nome_prof'] ?? '') );
        $cpf          = $only_num( wp_unslash($_POST['cpf'] ?? '') );

        // Passo 2
        $prest_contas  = $clean( wp_unslash($_POST['prest_contas'] ?? '') );
        $data_prest    = $clean( wp_unslash($_POST['data_prest'] ?? '') );
        $classificacao = $clean( wp_unslash($_POST['classificacao'] ?? '') );
        $descricao     = sanitize_textarea_field( wp_unslash($_POST['descricao'] ?? '') );
        $nome_curto    = $clean( wp_unslash($_POST['nome_curto'] ?? '') );
        $carga_horaria = $only_num( wp_unslash($_POST['carga_horaria'] ?? '') );

        // Passo 3
        $banco   = $clean( wp_unslash($_POST['banco']   ?? '') );
        $agencia = $only_num( wp_unslash($_POST['agencia'] ?? '') );
        $conta   = $only_num( wp_unslash($_POST['conta']   ?? '') );

        // Fallback e-mail do usuário logado
        if ( empty($email_prest) ) {
            $u = wp_get_current_user();
            if ( $u && ! empty($u->user_email) ) {
                $email_prest = sanitize_email($u->user_email);
            }
        }

        // ====== SEMPRE CRIA NOVO POST ======
        $user_id   = get_current_user_id();
        $titulo    = 'Solicitação - ' . ( $pessoa_tipo==='pj'
                        ? ( $nome_empresa ?: 'PJ' )
                        : ( $nome_prof    ?: 'PF' )
                     ) . ' - ' . current_time('Y-m-d H:i');

        $post_id = wp_insert_post([
            'post_type'     => 'apf_submission',
            'post_title'    => $titulo,
            'post_status'   => 'publish',
            'post_author'   => $user_id,
            // força a data "agora" pra subir no topo
            'post_date'     => current_time('mysql'),
            'post_date_gmt' => get_gmt_from_date( current_time('mysql') ),
        ]);

        if ( $post_id && ! is_wp_error($post_id) ) {
            // Grava metas
            $meta = [
                // passo 1
                'nome_diretor'   => $nome_diretor,
                'num_controle'   => $num_controle,
                'tel_prestador'  => $tel_prestador,
                'email_prest'    => $email_prest,
                'num_doc_fiscal' => $num_doc_fiscal,
                'valor'          => $valor_norm,
                'pessoa_tipo'    => $pessoa_tipo,
                'nome_empresa'   => $nome_empresa,
                'cnpj'           => $cnpj,
                'nome_prof'      => $nome_prof,
                'cpf'            => $cpf,
                // passo 2
                'prest_contas'   => $prest_contas,
                'data_prest'     => $data_prest,
                'classificacao'  => $classificacao,
                'descricao'      => $descricao,
                'nome_curto'     => $nome_curto,
                'carga_horaria'  => $carga_horaria,
                // passo 3
                'banco'          => $banco,
                'agencia'        => $agencia,
                'conta'          => $conta,
            ];
            foreach ($meta as $k => $v) {
                update_post_meta($post_id, 'apf_'.$k, $v);
            }

            $out .= '<div style="padding:12px;border:1px solid #cde;border-radius:8px;background:#f7fbff;margin-bottom:16px">Formulário enviado com sucesso.</div>';
        } else {
            $out .= '<div style="padding:12px;border:1px solid #f3c;border-radius:8px;background:#fff5f8;margin-bottom:16px;color:#b00020">Não foi possível enviar agora.</div>';
        }
    }

    // ====== FORM HTML (3 abas) ======
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
              <?php if ( ! empty($apf_directors) ) : ?>
                <select name="nome_diretor" required>
                  <option value="" disabled <?php echo selected($nome_diretor, '', false); ?>>Selecione um diretor</option>
                  <?php foreach ( $apf_directors as $dir_entry ) :
                      $dir_name = isset($dir_entry['director']) ? trim((string) $dir_entry['director']) : '';
                      if ( $dir_name === '' ) { continue; }
                      $dir_course = isset($dir_entry['course']) ? trim((string) $dir_entry['course']) : '';
                      $label = $dir_course ? $dir_name . ' — ' . $dir_course : $dir_name;
                      $selected = selected($nome_diretor, $dir_name, false);
                  ?>
                    <option value="<?php echo esc_attr($dir_name); ?>" data-course="<?php echo esc_attr($dir_course); ?>" data-full-label="<?php echo esc_attr($label); ?>" data-director-label="<?php echo esc_attr($dir_name); ?>" title="<?php echo esc_attr($label); ?>" <?php echo $selected; ?>>
                      <?php echo esc_html($label); ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ( $nome_diretor !== '' && empty($apf_director_names[$nome_diretor]) ) : ?>
                    <option value="<?php echo esc_attr($nome_diretor); ?>" data-course="" data-full-label="<?php echo esc_attr($nome_diretor . ' (manual)'); ?>" data-director-label="<?php echo esc_attr($nome_diretor); ?>" selected title="<?php echo esc_attr($nome_diretor); ?>">
                      <?php echo esc_html($nome_diretor . ' (manual)'); ?>
                    </option>
                  <?php endif; ?>
                </select>
              <?php else : ?>
                <input type="text" name="nome_diretor" required>
                <span class="apf-field-note">Cadastre diretores no painel financeiro para habilitar a seleção.</span>
              <?php endif; ?>
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
      .apf-grid input,.apf-grid textarea,.apf-grid select{border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff;color:#344054;width:100%;max-width:100%;box-sizing:border-box}
      .apf-grid select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image:linear-gradient(45deg,transparent 50%,#475067 50%),linear-gradient(135deg,#475067 50%,transparent 50%),linear-gradient(to right,transparent,transparent);background-position:calc(100% - 18px) 55%,calc(100% - 13px) 55%,100% 0;background-size:5px 5px,5px 5px,40px 100%;background-repeat:no-repeat;padding-right:42px}
      .apf-row{display:flex;gap:18px;border:none;margin:10px 0 0;padding:0}
      .apf-radio{display:flex;align-items:center;gap:6px;font-size:14px}
      .apf-actions{display:flex;justify-content:space-between;margin-top:16px}
      .apf-actions button{background:#1f6feb;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
      .apf-actions .apf-prev{background:#a0a7b4}
      .apf-field-note{display:block;font-size:12px;color:#b42318;margin-top:6px}
      @media(min-width:640px){ .apf-grid{grid-template-columns:1fr 1fr} }
    </style>

    <script>
    (function(){
      const form  = document.getElementById('apfForm');
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

      const directorSelect = form.querySelector('select[name="nome_diretor"]');
      const courseInput = form.querySelector('input[name="nome_curto"]');
      if (directorSelect) {
        const syncCourse = () => {
          if (!courseInput) return;
          const option = directorSelect.options[directorSelect.selectedIndex];
          if (!option) return;
          const course = option.getAttribute('data-course') || '';
          if (course) {
            courseInput.value = course;
          }
        };
        const restoreFullLabels = () => {
          Array.from(directorSelect.options).forEach(opt => {
            const fullLabel = opt.getAttribute('data-full-label');
            if (fullLabel !== null) {
              opt.textContent = fullLabel;
            }
          });
        };
        const applyDirectorLabels = () => {
          restoreFullLabels();
          const current = directorSelect.options[directorSelect.selectedIndex];
          if (current) {
            const directorLabel = current.getAttribute('data-director-label');
            if (directorLabel) {
              current.textContent = directorLabel;
            }
          }
        };
        directorSelect.addEventListener('change', () => {
          syncCourse();
          applyDirectorLabels();
        });
        directorSelect.addEventListener('focus', restoreFullLabels);
        directorSelect.addEventListener('blur', applyDirectorLabels);
        if (!courseInput || !courseInput.value) {
          syncCourse();
        }
        applyDirectorLabels();
      }

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

/* ====== includes ====== */
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/admin-meta.php';
require_once __DIR__ . '/includes/portal.php';
