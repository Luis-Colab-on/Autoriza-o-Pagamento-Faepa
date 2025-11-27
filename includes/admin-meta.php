<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Metabox de visualização/edição dos dados do formulário
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'apf_meta_box',
        'Dados da Solicitação (FAEPA)',
        'apf_render_meta_box',
        'apf_submission',
        'normal',
        'high'
    );
});

function apf_render_meta_box( $post ){
    // lista dos campos salvos pelo form
    $fields = array(
        'nome_diretor'   => 'Nome Coordenador',
        'num_controle'   => 'Nº Controle Secretaria',
        'tel_prestador'  => 'Telefone do Prestador',
        'email_prest'    => 'E-mail do Prestador',
        'num_doc_fiscal' => 'Nº Documento Fiscal',
        'valor'          => 'Valor (R$)',
        'pessoa_tipo'    => 'Tipo (pf/pj)',
        'nome_empresa'   => 'Nome Empresa (PJ)',
        'nome_colaborador'=> 'Nome do Colaborador (PJ)',
        'cnpj'           => 'CNPJ (PJ)',
        'nome_prof'      => 'Nome Profissional (PF)',
        'cpf'            => 'CPF (PF)',
        'prest_contas'   => 'Prestação de Contas',
        'data_prest'     => 'Data Prestação',
        'classificacao'  => 'Classificação',
        'descricao'      => 'Descrição',
        'nome_curto'     => 'Nome Curto do Curso',
        'carga_horaria'  => 'Carga Horária',
        'banco'          => 'Banco',
        'agencia'        => 'Agência',
        'conta'          => 'Conta',
    );

    wp_nonce_field('apf_meta_save','apf_meta_nonce');

    echo '<table class="form-table">';
    foreach ($fields as $key => $label){
        $val = get_post_meta($post->ID, 'apf_'.$key, true);
        echo '<tr>';
        echo '<th style="width:220px;"><label for="apf_'.$key.'">'.esc_html($label).'</label></th>';
        echo '<td>';
        if ( $key === 'descricao' ) {
            echo '<textarea id="apf_'.$key.'" name="apf_'.$key.'" rows="4" style="width:100%;">'.esc_textarea($val).'</textarea>';
        } else {
            echo '<input type="text" id="apf_'.$key.'" name="apf_'.$key.'" value="'.esc_attr($val).'" style="width:100%;">';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';

    echo '<p><em>Dica:</em> os dados também podem ser listados via shortcode <code>[apf_inbox]</code> numa página.</p>';
}

add_action('save_post_apf_submission', function( $post_id ){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! isset($_POST['apf_meta_nonce']) || ! wp_verify_nonce($_POST['apf_meta_nonce'], 'apf_meta_save') ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    $keys = array(
        'nome_diretor','num_controle','tel_prestador','email_prest','num_doc_fiscal','valor',
        'pessoa_tipo','nome_empresa','nome_colaborador','cnpj','nome_prof','cpf',
        'prest_contas','data_prest','classificacao','descricao','nome_curto','carga_horaria',
        'banco','agencia','conta'
    );

    foreach ($keys as $k){
        if ( isset($_POST['apf_'.$k]) ) {
            $v = is_array($_POST['apf_'.$k]) ? '' : wp_unslash($_POST['apf_'.$k]);
            // sanitização básica
            if ( in_array($k, array('num_controle','tel_prestador','cnpj','cpf','carga_horaria','agencia','conta'), true) ) {
                $v = preg_replace('/\D+/', '', $v);
            } elseif ( $k === 'email_prest' ) {
                $v = sanitize_email($v);
            } elseif ( $k === 'descricao' ) {
                $v = sanitize_textarea_field($v);
            } else {
                $v = sanitize_text_field($v);
            }
            update_post_meta($post_id, 'apf_'.$k, $v);
        }
    }
});
