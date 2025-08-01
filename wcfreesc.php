<?php
/**
 * Woocommerce Free Shipping Class
 *
 * Plugin Name: Woocommerce Free Shipping Class
 * Version:     2.4.4
 * Plugin URI:  https://singularity.art.br/
 * Description: Permite atribuir classes aos métodos de envio frete grátis e determina sua prioridade exibição, por regras.
 * Author:      Rafael Moreno
 * Author URI:  https://singularity.art.br/
 * Text Domain: wcfreesc
 * Domain Path: /languages/
 *
 * @package GTM Kit
 * @copyright Copyright (C) 2022-2025, Singularity Art.br
 */

if (!defined('ABSPATH')) exit;


// FREE SHIPPING_LABEL
add_filter( 'woocommerce_cart_shipping_method_full_label', 'bbloomer_add_0_to_shipping_label', 10, 2 );
function bbloomer_add_0_to_shipping_label( $label, $method ) {
    
    if ((is_account_page() || is_checkout() || is_cart()) && is_admin() && !defined('DOING_AJAX')) return;
    
    // if shipping rate is 0, concatenate ": $0.00" to the label
    if ( ! ( $method->cost > 0 ) ) {
    $label .= ': <strong><span style="color: #50b848;">GRÁTIS</span></strong>';
    }
    return $label;
}


// Adiciona um campo de classe de entrega aos métodos de frete grátis
add_filter( 'woocommerce_shipping_instance_form_fields_free_shipping', 'adicionar_classe_entrega_frete_gratis' );

function adicionar_classe_entrega_frete_gratis( $fields ) {
    $shipping_classes = WC()->shipping()->get_shipping_classes();
    $options = ['' => 'Todas as classes'];

    foreach ( $shipping_classes as $class ) {
        $options[ $class->slug ] = $class->name;
    }

    $fields['frete_gratis_para_classe'] = [
        'title'       => 'Classe de Entrega',
        'type'        => 'select',
        'description' => 'Opcional. Limita este frete grátis a uma classe de entrega específica.',
        'default'     => '',
        'desc_tip'    => true,
        'options'     => $options,
    ];

    return $fields;
}

add_filter( 'woocommerce_package_rates', 'filtrar_metodos_por_classe_prioritaria', 20, 2 );

function filtrar_metodos_por_classe_prioritaria( $rates, $package ) {
    $prioridade_classes = ['volumetric-weight', 'brasil'];
    $classes_no_carrinho = [];

    foreach ( $package['contents'] as $item ) {
        $classe = $item['data']->get_shipping_class();
        if ( $classe && ! in_array( $classe, $classes_no_carrinho ) ) {
            $classes_no_carrinho[] = $classe;
        }
    }

    if ( empty( $classes_no_carrinho ) ) return $rates;

    // Determina a classe prioritária no carrinho
    $classe_prioritaria = null;
    foreach ( $prioridade_classes as $classe ) {
        if ( in_array( $classe, $classes_no_carrinho ) ) {
            $classe_prioritaria = $classe;
            break;
        }
    }

    if ( ! $classe_prioritaria ) return $rates;

    // Encontrar a zona de entrega correspondente ao pacote (com base em postcode, etc.)
    $zone = WC_Shipping_Zones::get_zone_matching_package( $package );
    $zone_methods = $zone->get_shipping_methods();
    $metodos_por_id = [];

    foreach ( $zone_methods as $method ) {
        $metodos_por_id[ $method->instance_id ] = $method;
    }

    $rates_filtradas = [];

    foreach ( $rates as $rate_id => $rate ) {
        $instance_id = $rate->instance_id;

        // Verificamos apenas os métodos que realmente pertencem à zona ativa
        if ( ! isset( $metodos_por_id[ $instance_id ] ) ) {
            continue; // pula métodos que não estão na zona válida
        }

        $metodo = $metodos_por_id[ $instance_id ];

        if ( $rate->method_id === 'free_shipping' ) {
            $classe_configurada = $metodo->get_option( 'frete_gratis_para_classe' );

            if ( empty( $classe_configurada ) || $classe_configurada === $classe_prioritaria ) {
                $rates_filtradas[ $rate_id ] = $rate;
            }

        } else {
            // Mantém se for da classe prioritária ou se for método genérico (sem classe definida)
            if (
                strpos( $rate_id, $classe_prioritaria ) !== false ||
                strpos( strtolower( $rate->label ), $classe_prioritaria ) !== false
            ) {
                $rates_filtradas[ $rate_id ] = $rate;
            } else {
                // Mantém também se o método não estiver amarrado a nenhuma classe específica
                // (isso vai depender da sua regra de nomeação ou configuração do método)
                $rates_filtradas[ $rate_id ] = $rate;
            }
        }
    }

    return !empty( $rates_filtradas ) ? $rates_filtradas : $rates;
}

// ESPUMAS SOB MEDIDA MODIFICATION
add_action('woocommerce_check_cart_items', 'limitar_espumas_para_frete_gratis');
function limitar_espumas_para_frete_gratis() {
    
    if ((is_account_page() || is_checkout() || is_cart()) && is_admin() && !defined('DOING_AJAX')) return;

    $valor_maximo = 600; // Limite em reais
    $categoria_alvo = 'volumetric-weight';
    $total_espumas = 0;

    // Verifica se o frete grtis foi selecionado
    $chosen_methods = WC()->session->get('chosen_shipping_methods');
    if (empty($chosen_methods)) return;

    $frete_gratis_ativo = false;
    foreach ($chosen_methods as $method) {
        if (strpos($method, 'free_shipping') !== false) {
            $frete_gratis_ativo = true;
            break;
        }
    }

    if (!$frete_gratis_ativo) return;

    // Soma os valores dos produtos da categoria "volumetric-weight"
    foreach (WC()->cart->get_cart() as $item) {
        $product = $item['data'];

        if (has_term($categoria_alvo, 'product_cat', $product->get_id())) {
            $total_espumas += $item['line_total'];
        }
    }

    if ($total_espumas > $valor_maximo) {
        wc_add_notice(
            sprintf(
                'Para utilizar o frete grátis, o valor dos produtos da categoria "Espumas e Enchimentos" não pode ultrapassar R$ %s. Atualmente : R$ %s.',
                number_format($valor_maximo, 2, ',', '.'),
                number_format($total_espumas, 2, ',', '.')
            ),
            'error'
        );
    }
}


add_filter( 'woocommerce_package_rates', 'aplicar_apenas_classe_prioritaria', 10, 2 );

function aplicar_apenas_classe_prioritaria( $rates, $package ) {
    // Define a ordem de prioridade das classes (do mais prioritrio para o menos)
    $prioridade_classes = ['volumetric-weight', 'brasil']; // slugs das classes

    $classes_no_carrinho = [];

    // Coletar todas as classes no carrinho
    foreach ( $package['contents'] as $item ) {
        $shipping_class = $item['data']->get_shipping_class();
        if ( $shipping_class && ! in_array( $shipping_class, $classes_no_carrinho ) ) {
            $classes_no_carrinho[] = $shipping_class;
        }
    }

    // Se não há classes ou só uma, não faz nada
    if ( count( $classes_no_carrinho ) <= 1 ) {
        return $rates;
    }

    // Descobrir a classe de maior prioridade presente no carrinho
    $classe_prioritaria = null;
    foreach ( $prioridade_classes as $classe ) {
        if ( in_array( $classe, $classes_no_carrinho ) ) {
            $classe_prioritaria = $classe;
            break;
        }
    }

    if ( ! $classe_prioritaria ) {
        return $rates; // Se não encontrou nenhuma prioridade válida, deixa como está
    }

    // Aqui, filtramos os métodos de envio para manter apenas os da classe prioritária
    $rates_filtradas = [];

    foreach ( $rates as $rate_id => $rate ) {
        // Assumimos que o método de envio tem relaço com a classe prioritária no ID ou título
        // Você pode adaptar aqui de acordo com sua lógica ou nomes de métodos
        if (
            strpos( $rate_id, $classe_prioritaria ) !== false ||
            strpos( strtolower( $rate->label ), $classe_prioritaria ) !== false
        ) {
            $rates_filtradas[ $rate_id ] = $rate;
        }
    }

    // Se não achou nenhuma que bate com a classe, deixa tudo (evita carrinho travado)
    return !empty($rates_filtradas) ? $rates_filtradas : $rates;
}



// remove a classe de entrega de espumas e enchimento (FUNCIONAL)
add_filter( 'woocommerce_package_rates', 'remover_frete_gratis_para_classe', 10, 2 );

function remover_frete_gratis_para_classe( $rates, $package ) {
    $classe_restrita = 'volumetric-weight'; // slug da shipping class
    $tem_classe_restrita = false;

    foreach ( $package['contents'] as $item ) {
        if ( $item['data']->get_shipping_class() === $classe_restrita ) {
            $tem_classe_restrita = true;
            break;
        }
    }

    if ( $tem_classe_restrita ) {
        foreach ( $rates as $rate_id => $rate ) {
            if ( 'free_shipping' === $rate->method_id ) {
                unset( $rates[ $rate_id ] );
                echo '<style>.ux-free-shipping { display: none !important; }</style>';
            }
        }
        wc_print_notice(
            'Espumas e enchimentos não são elegíveis para transporte com frete grátis. Tente refazer a simulação sem esses itens e se for possível efetuar a compra desses itens de forma separada',
            'notice' // Pode ser 'notice', 'error' ou 'success'
        );
    }

    return $rates;
}


//Adiciona o campo na tela de administração
add_filter('woocommerce_shipping_instance_form_fields_free_shipping', 'custom_add_shipping_class_to_free_shipping');

function custom_add_shipping_class_to_free_shipping($fields) {
    $shipping_classes = WC()->shipping()->get_shipping_classes();
    $options = ['' => __('Todas as classes', 'woocommerce')];

    foreach ($shipping_classes as $class) {
        $options[$class->term_id] = $class->name;
    }

    $fields['required_shipping_class'] = [
        'title'       => __('Classe de Entrega Requerida'),
        'type'        => 'select',
        'description' => __('Frete grátis será ativado somente se essa classe estiver no carrinho e o valor mínimo for atingido.'),
        'default'     => '',
        'options'     => $options,
    ];

    return $fields;
}

// Filtra os métodos de frete aplicando somente o frete grátis da maior exigência atingida
add_filter('woocommerce_package_rates', function($rates, $package) {
    if (is_admin() && !defined('DOING_AJAX')) return $rates;

    try {
        $package_total = 0;
        $classes_no_carrinho = [];

        // Identifica todas as classes de entrega no carrinho e calcula o total do pacote
        foreach ($package['contents'] as $item) {
            if (!isset($item['data']) || !$item['data'] instanceof WC_Product) continue;

            $product = $item['data'];
            $class_id = $product->get_shipping_class_id();
            if ($class_id) {
                $classes_no_carrinho[] = (string) $class_id;
            }

            $package_total += floatval($item['line_total']) + floatval($item['line_tax']);
        }

        $classes_no_carrinho = array_unique($classes_no_carrinho);

        $zone = WC_Shipping_Zones::get_zone_by_package($package);
        if (!$zone) return $rates;

        $methods = $zone->get_shipping_methods();
        $free_shipping_methods = [];

        // Mapeia os métodos de frete grtis válidos para as classes no carrinho
        foreach ($rates as $rate_id => $rate) {
            if (strpos($rate_id, 'free_shipping') === false) continue;

            foreach ($methods as $method) {
                if (!is_object($method) || $method->id !== 'free_shipping') continue;
                if (!isset($method->instance_id) || $method->instance_id != $rate->instance_id) continue;

                $settings = is_array($method->instance_settings ?? null) ? $method->instance_settings : [];
                $required_class = $settings['required_shipping_class'] ?? '';
                $min_amount = isset($settings['min_amount']) ? floatval($settings['min_amount']) : 0;

                if ($required_class === '' || in_array($required_class, $classes_no_carrinho)) {
                    $free_shipping_methods[] = [
                        'class_id' => $required_class,
                        'min'      => $min_amount,
                        'rate_id'  => $rate_id,
                        'rate'     => $rate
                    ];
                }
            }
        }

        // Agora vamos determinar a MAIOR exigência entre as classes presentes no carrinho
        $maior_exigencia = null;

        foreach ($free_shipping_methods as $method) {
            $classe = $method['class_id'];
            if ($classe === '' || in_array($classe, $classes_no_carrinho)) {
                if (!$maior_exigencia || $method['min'] > $maior_exigencia['min']) {
                    $maior_exigencia = $method;
                }
            }
        }

        if ($maior_exigencia && $package_total >= $maior_exigencia['min']) {
            // Ativa apenas o frete grátis da maior exigência
            $new_rates = [$maior_exigencia['rate_id'] => $maior_exigencia['rate']];

            // Adiciona fretes pagos
            foreach ($rates as $rate_id => $rate) {
                if (strpos($rate_id, 'free_shipping') === false) {
                    $new_rates[$rate_id] = $rate;
                }
            }

            return $new_rates;
        } else {
            // Remove todos os fretes grátis
            foreach ($rates as $rate_id => $rate) {
                if (strpos($rate_id, 'free_shipping') !== false) {
                    unset($rates[$rate_id]);
                }
            }
        }

    } catch (Throwable $e) {
        error_log("Erro em woocommerce_package_rates: " . $e->getMessage());
        return $rates;
    }

    return $rates;
}, 20, 2);
