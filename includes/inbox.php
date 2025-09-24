<?php
if ( ! defined('ABSPATH') ) { exit; }

/* ====== DASHBOARD: shortcode [apf_inbox] ====== */
add_shortcode('apf_inbox', function () {

    // só logados com permissão básica de edição (ajuste se quiser mais restrito)
    if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) {
        return '<p>Faça login com um usuário autorizado para ver as submissões.</p>';
    }

    $m = function($id,$k){ return get_post_meta($id, 'apf_'.$k, true); };

    $q = new WP_Query([
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 200,
    ]);

    ob_start(); ?>
    <div class="apf-card" style="max-width:100%;padding:0">
      <div style="overflow:auto;border-radius:12px;border:1px solid #e6e9ef">
        <table style="border-collapse:collapse;width:100%;min-width:960px;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;font-size:13px">
          <thead style="background:#f7f8fb">
            <tr>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Data</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Tipo</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Nome/Empresa</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Telefone</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">E-mail</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Valor (R$)</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Doc. Fiscal</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Data Serviço</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Classificação</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Curso</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">CH</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Banco/Agência/Conta</th>
              <th style="text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef">Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php
          if ($q->have_posts()):
            while ($q->have_posts()): $q->the_post();
              $id   = get_the_ID();
              $tipo = $m($id,'pessoa_tipo'); // pf/pj
              $nome = ($tipo==='pj') ? $m($id,'nome_empresa') : $m($id,'nome_prof');

              $valor = $m($id,'valor');
              $valor_fmt = $valor !== '' ? number_format(floatval($valor), 2, ',', '.') : '';

              $banco = trim(($m($id,'banco') ?: '').' / '.($m($id,'agencia') ?: '').' / '.($m($id,'conta') ?: ''));
              $edit_link = admin_url('post.php?post='.$id.'&action=edit');
          ?>
            <tr>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html(get_the_date('Y-m-d H:i')); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;text-transform:uppercase;"><?php echo esc_html($tipo ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($nome ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'tel_prestador') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'email_prest') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($valor_fmt ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'num_doc_fiscal') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'data_prest') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'classificacao') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'nome_curto') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($m($id,'carga_horaria') ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;"><?php echo esc_html($banco ?: '—'); ?></td>
              <td style="border-bottom:1px solid #f0f2f5;padding:10px 12px;">
                <a href="<?php echo esc_url($edit_link); ?>" target="_blank">Ver no Admin</a>
              </td>
            </tr>
          <?php
            endwhile; wp_reset_postdata();
          else:
          ?>
            <tr><td colspan="13" style="padding:12px">Nenhuma submissão encontrada.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
