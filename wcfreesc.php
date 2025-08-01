<?php
/**
 * Plugin Name: Frete Grátis por Classe
 * Description: Oferece frete grátis condicionado por classe de entrega e valor mínimo, aplica apenas a classe prioritária no carrinho e limita "Espumas e Enchimentos" quando frete grátis está ativo.
 * Version: 1.1
 * Author: Rafael Moreno
 * Text Domain: frete-gratis-por-classe
 */

if (!defined('ABSPATH')) {
    exit;
}

// Só inicializa se o WooCommerce estiver ativo
if (!class_exists('WooCommerce')) {
    return;
}

class FGPC_Frete_Gratis_Por_Classe {

    private const LOG_SOURCE = 'frete-gratis-por-classe';

    public function __construct() {
        // Modifica o label de frete grátis
        add_filter('woocommerce_cart_shipping_method_full_label', [__CLASS__, 'modify_free_shipping_label'], 10, 2);

        // Adiciona campo de classe de entrega nas instâncias de frete grátis
        add_filter('woocommerce_shipping_instance_form_fields_free_shipping', [__CLASS__, 'add_free_shipping_class_field']);

        // Filtra métodos de entrega com base na classe prioritária
        add_filter('woocommerce_package_rates', [__CLASS__, 'filter_package_rates_by_priority'], 20, 2);

        // Validação específica de "Espumas e Enchimentos" quando frete grátis está ativo
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_espumas_limit']);
    }

    /**
     * Retorna se está em modo debug (para log)
     */
    private static function is_debug(): bool {
        return (bool) apply_filters('fgpc_debug', false);
    }

    /**
     * Logger interno condicional (só se debug estiver ativo)
     */
    private static function log(string $message, string $level = 'info'): void {
        if (!self::is_debug()) {
            return;
        }
        $logger = wc_get_logger();
        $context = ['source' => self::LOG_SOURCE];
        $logger->log($level, $message, $context);
    }

    /**
     * Prioridade de classes (slugs), do mais alto para o mais baixo.
     * Exemplo padrão: espumas-e-enchimentos antes de brasil.
     */
    private static function get_prioridade_classes(): array {
        return (array) apply_filters('fgpc_prioridade_classes', ['espumas-e-enchimentos', 'brasil']);
    }

    /**
     * Categoria alvo para a validação de limite (slug)
     */
    private static function get_categoria_espumas(): string {
        return (string) apply_filters('fgpc_categoria_espumas_slug', 'espumas-e-enchimentos');
    }

    /**
     * Valor máximo para produtos da categoria de espumas permitidos com frete grátis ativo
     */
    private static function get_limite_espumas(): float {
        return floatval(apply_filters('fgpc_limite_espumas', 600));
    }

    /**
     * Verifica se frete grátis foi escolhido nas shipping methods atuais da sessão
     */
    private static function is_free_shipping_chosen(): bool {
        $chosen = WC()->session->get('chosen_shipping_methods', []);
        if (!is_array($chosen)) {
            return false;
        }
        foreach ($chosen as $method) {
            if (strpos($method, 'free_shipping') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adiciona o sufixo "GRÁTIS" ao label quando o custo é zero.
     */
    public static function modify_free_shipping_label($label, $method) {
        // Não fazer nada em admin não AJAX
        if (is_admin() && !defined('DOING_AJAX')) {
            return $label;
        }

        // Se o custo é zero ou vazio, marca como grátis
        if (!($method->cost > 0)) {
            $label .= ': <strong><span style="color: #50b848;">' . esc_html__('GRÁTIS', 'frete-gratis-por-classe') . '</span></strong>';
        }

        return $label;
    }

    /**
     * Adiciona campo de classe de entrega opcional na instância de frete grátis
     */
    public static function add_free_shipping_class_field($fields) {
        $shipping_classes = WC()->shipping()->get_shipping_classes();
        $options = ['' => esc_html__('Todas as classes', 'frete-gratis-por-classe')];

        foreach ($shipping_classes as $class) {
            $options[$class->slug] = $class->name;
        }

        $fields['frete_gratis_para_classe'] = [
            'title'       => esc_html__('Classe de Entrega', 'frete-gratis-por-classe'),
            'type'        => 'select',
            'description' => esc_html__('Opcional. Limita este frete grátis a uma classe de entrega específica.', 'frete-gratis-por-classe'),
            'default'     => '',
            'desc_tip'    => true,
            'options'     => $options,
        ];

        return $fields;
    }

    /**
     * Filtra os métodos de envio mantendo apenas os compatíveis com a classe prioritária do carrinho.
     */
    public static function filter_package_rates_by_priority($rates, $package) {
        // Segurança: se só uma classe ou nenhuma, não interpõe
        $prioridades = self::get_prioridade_classes();
        self::log('Prioridades configuradas: ' . implode(', ', $prioridades));

        $classes_no_carrinho = [];

        foreach ($package['contents'] as $item) {
            if (empty($item['data']) || !is_object($item['data'])) {
                continue;
            }
            $shipping_class = $item['data']->get_shipping_class(); // slug
            if ($shipping_class && !in_array($shipping_class, $classes_no_carrinho, true)) {
                $classes_no_carrinho[] = $shipping_class;
            }
        }

        if (count($classes_no_carrinho) <= 1) {
            self::log('Uma ou nenhuma classe no carrinho, preservando todas as taxas.', 'debug');
            return $rates;
        }

        // Determina a classe prioritária presente no carrinho
        $classe_prioritaria = null;
        foreach ($prioridades as $classe) {
            if (in_array($classe, $classes_no_carrinho, true)) {
                $classe_prioritaria = $classe;
                break;
            }
        }

        if (!$classe_prioritaria) {
            self::log('Nenhuma classe prioritária detectada no carrinho (' . implode(', ', $classes_no_carrinho) . '), não filtrando.', 'debug');
            return $rates;
        }

        self::log("Classe prioritária selecionada: {$classe_prioritaria}");

        // Tenta achar a zona correspondente para acessar métodos e opções
        $zone = WC_Shipping_Zones::get_zone_matching_package($package);
        $zone_methods = $zone ? $zone->get_shipping_methods() : [];
        $metodos_por_instance = [];
        foreach ($zone_methods as $method) {
            if (isset($method->instance_id)) {
                $metodos_por_instance[$method->instance_id] = $method;
            }
        }

        $filtered = [];

        foreach ($rates as $rate_id => $rate) {
            $keep = false;

            // Lida com frete grátis respeitando a classe configurada na instância
            if ($rate->method_id === 'free_shipping') {
                $instance_id = $rate->instance_id;
                $metodo = $metodos_por_instance[$instance_id] ?? null;
                $classe_configurada = '';
                if ($metodo && is_callable([$metodo, 'get_option'])) {
                    $classe_configurada = $metodo->get_option('frete_gratis_para_classe', '');
                }

                if (empty($classe_configurada) || $classe_configurada === $classe_prioritaria) {
                    $keep = true;
                } else {
                    self::log("Removendo frete grátis {$rate_id} porque é para classe '{$classe_configurada}', não '{$classe_prioritaria}'", 'debug');
                }
            } else {
                // Para outros métodos: tenta casar pela presença da slug da classe no rate_id ou label
                $label_lower = strtolower($rate->label);
                if (strpos($rate_id, $classe_prioritaria) !== false || strpos($label_lower, strtolower($classe_prioritaria)) !== false) {
                    $keep = true;
                } else {
                    // fallback: mantém também se nenhum outro foi mantido (será resolvido depois)
                    self::log("Método {$rate_id} não corresponde à classe prioritária '{$classe_prioritaria}', removendo tentativamente.", 'debug');
                }
            }

            if ($keep) {
                $filtered[$rate_id] = $rate;
            }
        }

        if (!empty($filtered)) {
            self::log('Retornando rates filtradas por classe prioritária.', 'debug');
            return $filtered;
        }

        // fallback: evita travar carrinho
        self::log('Filtro resultou em vazio; voltando aos rates originais.', 'debug');
        return $rates;
    }

    /**
     * Validação da categoria de "Espumas e Enchimentos" quando frete grátis está ativo.
     */
    public static function validate_espumas_limit() {
        // Não rodar em admin não AJAX
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Só aplica se frete grátis estiver ativo/escolhido
        if (!self::is_free_shipping_chosen()) {
            self::log('Frete grátis não selecionado; pulando validação de espumas.', 'debug');
            return;
        }

        $categoria_alvo = self::get_categoria_espumas();
        $limite = self::get_limite_espumas();
        $total_espumas = 0;

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if (!is_object($product)) {
                continue;
            }

            if (has_term($categoria_alvo, 'product_cat', $product->get_id())) {
                $total_espumas += floatval($item['line_total']);
            }
        }

        self::log("Total de '{$categoria_alvo}' no carrinho: {$total_espumas}; limite: {$limite}", 'debug');

        if ($total_espumas > $limite) {
            wc_add_notice(
                sprintf(
                    /* translators: %1$s = limite formatado, %2$s = total atual formatado */
                    __('Para utilizar o frete grátis, o valor dos produtos da categoria "%1$s" não pode ultrapassar R$ %2$s. Atualmente: R$ %3$s.', 'frete-gratis-por-classe'),
                    esc_html__('Espumas e Enchimentos', 'frete-gratis-por-classe'),
                    number_format($limite, 2, ',', '.'),
                    number_format($total_espumas, 2, ',', '.')
                ),
                'error'
            );
        }
    }
}

// Inicializa o plugin
new FGPC_Frete_Gratis_Por_Classe();
