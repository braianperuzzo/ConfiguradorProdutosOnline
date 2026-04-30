<?php

if (!function_exists('renderHiddenSeoText')) {
    /**
     * @param string $heading
     * @param string[] $paragraphs
     */
    function renderHiddenSeoText(string $heading, array $paragraphs): string
    {
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $items = [];

        foreach ($paragraphs as $paragraph) {
            $trimmed = trim((string) $paragraph);
            if ($trimmed === '') {
                continue;
            }
            $items[] = '<p>' . htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if (empty($items)) {
            return '';
        }

        $items[] = '<p>Resumo técnico da rota: ' . $safeHeading . '. Este texto complementa a indexação com contexto sobre seleção, aplicação, compatibilidade e próximos passos de especificação, mantendo a mesma experiência visual para quem navega no site.</p>';

        return '<section aria-label="Conteúdo complementar para SEO" class="sr-only" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0, 0, 0, 0);white-space:nowrap;border:0;">'
            . '<h2>' . $safeHeading . '</h2>'
            . implode('', $items)
            . '</section>';
    }
}
