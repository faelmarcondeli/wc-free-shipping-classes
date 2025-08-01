=== Frete Gratis Por Classe de Entrega ===
Tags: frete, cotação, logística, envio, melhor envio
Requires at least: 6.0
Tested up to: 6.8.2
Requires PHP: 7.4+
Requires Wordpress 4.0+
Requires WooCommerce 4.0+
Utilizes Wordpress 6.8.2
Utilizes WooCommerce 10.0.4
Utilizes Wordpress Theme Flastome: 3.19.15
Utilizes Melhorias no e-mail do Woocommerce
Utilizes Woocommerce Índices de pesquisa de texto completo do HPOS
Utilizes Woocommerce Novo editor de produtos (Beta)
Utilizes LitespeedCache 7.3.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html


Plugin para permitir fretes utilizando mais de uma classe de entrega, as classes de entregas usadas
são as mesmas que podem ser criadas no Woocommerce. O plugin utiliza as classes para tratar
funções. As classes de entregas disponíveis ficam listadas na Configuração de envio de Método Frete
Grátis do Woocommerce. Configurar o Método de Envio para Frete Grátis permite que sejam aplicados
configurações de fretes grátis com condições diferentes (como valor mínimo por exemplo).

O método de envio frete grátis que agora podem ter valores mínimos diferentes são exibidos ao cliente
no carrinho do woocommerce por prioridade, por padrão isso é feito para a classe volumetric-weight
e quando na cotação de fretes há um produto pertencente a classe volumetric-weight que é a padrão,
então somente ela é exibida. (no momento não há inteção de permitir um valor para as prioridades, caso
essa exista a padrão ela é a prioritária)

A disponibilização da opção frete grátis está sujeita a análise da prioridade, uma vez que é disponibilizada
apenas uma opção de frete grátis, quando há um produto da classe prioritária somente ele é exibido, 
respeitando as condições citadas acima.

Quando um produto da classe volumetric-weight está presente na compra, então o valor máximo para obter
qualquer método frete grátis é limitado ao valor monetário padrão de 600, se na compra o total
de produtos da classe prioritária for menor que esse limite então é disponilizado o método de envio
grátis prioritário. Se a soma do total monetario de produtos da classe prioritária for maior que
o limite padrão então não será oferecido nenhuma opção de frete grátis e o woocommerce informa:
'Para utilizar o frete grátis, o valor dos produtos da categoria "Espumas e Enchimentos" não pode ultrapassar R$ %s. Atualmente : R$ %s.'

Ele também exibe a palavra 'GRÁTIS' branca como uma label de 50b848 e borda de raio 5, no lugar
do valor monetario 0

No Tema Flatsome exibe valores mínimos atualizados conforme o configurado na classe prioritaria
/wp-content/themes/flatsome/inc/woocommerce/class-shipping.php > public function free_shipping() 

## METAS
1 - Trocar o texto da mensagem "Espumas e Enchimentos" para que seja exibido o nome da classe de entrega
2 - A opção de frete grátis para não está funcionando corretamente quando há um produto da classe de entrega
prioritária na compra


