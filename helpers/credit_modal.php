<?php
declare(strict_types=1);

if (!function_exists('bidmap_creditos_checkout_url')) {
    function bidmap_creditos_checkout_url(int $valor): string
    {
        $fallbacks = [
            25 => 'https://checkout.bidmap.com.br/pay/25-creditos',
            50 => 'https://checkout.bidmap.com.br/pay/50-creditos',
            100 => 'https://checkout.bidmap.com.br/pay/100-creditos-bidmap',
        ];

        $fallback = $fallbacks[$valor] ?? '#';
        $url = trim((string) bidmap_env('BIDMAP_CHECKOUT_CREDITOS_' . $valor, $fallback));

        return $url !== '' && $url !== '#' ? $url : $fallback;
    }
}

if (!function_exists('bidmap_render_credit_modal')) {
    function bidmap_render_credit_modal(float $creditos): void
    {
        $checkoutCreditos = [
            25 => bidmap_creditos_checkout_url(25),
            50 => bidmap_creditos_checkout_url(50),
            100 => bidmap_creditos_checkout_url(100),
        ];
        ?>
        <div class="credit-modal" data-credit-modal hidden>
            <div class="credit-modal__backdrop" data-credit-modal-close></div>
            <section class="credit-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="credit-modal-title">
                <button class="credit-modal__close" type="button" aria-label="Fechar" data-credit-modal-close>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>

                <div class="credit-modal__icon" aria-hidden="true">
                    <i class="fa-solid fa-coins"></i>
                </div>

                <h2 id="credit-modal-title">Adicionar créditos</h2>
                <div class="credit-current-balance">
                    Saldo atual
                    <strong><?= htmlspecialchars(number_format($creditos, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?> créditos</strong>
                </div>
                <p>Escolha um pacote para abrir o checkout. Quando o pagamento for confirmado, o saldo será atualizado na sua conta.</p>

                <a class="credit-extract-link" href="historico_consultas.php">
                    Ver extrato de cr&eacute;ditos
                </a>

                <div class="credit-packages">
                    <?php foreach ($checkoutCreditos as $valor => $url): ?>
                        <a class="credit-package" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                            <span>Adicionar</span>
                            <strong>R$ <?= htmlspecialchars(number_format((float) $valor, 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <script>
            (function () {
                const modal = document.querySelector('[data-credit-modal]');

                if (!modal) {
                    return;
                }

                function openModal() {
                    modal.hidden = false;
                    document.body.classList.add('modal-open');
                }

                function closeModal() {
                    modal.hidden = true;
                    document.body.classList.remove('modal-open');
                }

                document.querySelectorAll('[data-credit-modal-open]').forEach(function (button) {
                    button.addEventListener('click', openModal);
                });

                window.bidmapOpenCreditModal = openModal;
                window.bidmapCloseCreditModal = closeModal;

                modal.querySelectorAll('[data-credit-modal-close]').forEach(function (button) {
                    button.addEventListener('click', closeModal);
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && !modal.hidden) {
                        closeModal();
                    }
                });
            })();
        </script>
        <?php
    }
}
