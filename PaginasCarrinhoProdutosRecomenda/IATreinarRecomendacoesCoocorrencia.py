#!/usr/bin/env python3
import argparse
import json
import os
from collections import Counter, defaultdict
from datetime import datetime, timezone

CODIGO_KEYS = [
    "codigo",
    "codigoProduto",
    "cd_produto",
    "cdProduto",
    "CD_PRODUTO",
    "id",
    "produto",
]


def normalizar_codigo(valor: str) -> str:
    if not valor:
        return ""
    return str(valor).strip().upper()


def extrair_codigo(item) -> str:
    if not isinstance(item, dict):
        return ""
    for chave in CODIGO_KEYS:
        if chave in item:
            codigo = normalizar_codigo(item.get(chave))
            if codigo:
                return codigo
    return ""


def carregar_cestas(diretorio: str):
    arquivos = [
        os.path.join(diretorio, nome)
        for nome in os.listdir(diretorio)
        if nome.lower().endswith(".json")
    ]
    cestas = []
    mtimes = []
    for caminho in arquivos:
        try:
            with open(caminho, "r", encoding="utf-8") as arquivo:
                dados = json.load(arquivo)
        except Exception:
            continue
        if not isinstance(dados, list):
            continue
        codigos = {extrair_codigo(item) for item in dados}
        codigos = {codigo for codigo in codigos if codigo}
        if not codigos:
            continue
        cestas.append(sorted(codigos))
        try:
            mtimes.append(os.path.getmtime(caminho))
        except OSError:
            pass
    return cestas, mtimes


def limpar_historico_antigo(diretorio: str, dias: int):
    if dias <= 0:
        return 0
    limite = datetime.now(timezone.utc).timestamp() - (dias * 86400)
    removidos = 0
    for nome in os.listdir(diretorio):
        if not nome.lower().endswith(".json"):
            continue
        caminho = os.path.join(diretorio, nome)
        try:
            if os.path.getmtime(caminho) < limite:
                os.remove(caminho)
                removidos += 1
        except OSError:
            continue
    return removidos


def gerar_modelo(cestas, minimo_coocorrencia: int, limite_por_produto: int, limite_global: int):
    coocorrencias = defaultdict(Counter)
    contagem_global = Counter()

    for cesta in cestas:
        contagem_global.update(cesta)
        for i, codigo in enumerate(cesta):
            for outro in cesta[i + 1 :]:
                coocorrencias[codigo][outro] += 1
                coocorrencias[outro][codigo] += 1

    produtos = {}
    for codigo, contagem in coocorrencias.items():
        recomendacoes = [
            {"codigo": outro, "score": int(score)}
            for outro, score in contagem.most_common(limite_por_produto)
            if score >= minimo_coocorrencia
        ]
        if recomendacoes:
            produtos[codigo] = recomendacoes

    global_recs = [
        {"codigo": codigo, "score": int(score)}
        for codigo, score in contagem_global.most_common(limite_global)
    ]

    return produtos, global_recs


def main():
    parser = argparse.ArgumentParser(description="Treina recomendações por co-ocorrência de produtos")
    parser.add_argument("--historico-dir", required=True, help="Diretório com histórico de carrinhos")
    parser.add_argument("--saida-modelo", required=True, help="Arquivo JSON de saída")
    parser.add_argument("--minimo-coocorrencia", type=int, default=2)
    parser.add_argument("--limite-por-produto", type=int, default=12)
    parser.add_argument("--limite-global", type=int, default=24)
    parser.add_argument("--limpar-historico-dias", type=int, default=0)

    args = parser.parse_args()

    if not os.path.isdir(args.historico_dir):
        raise SystemExit("Diretório de histórico não encontrado")

    limpar_historico_antigo(args.historico_dir, args.limpar_historico_dias)

    cestas, mtimes = carregar_cestas(args.historico_dir)
    treinado_em = datetime.now(timezone.utc).isoformat()

    janela_inicio = None
    janela_fim = None
    if mtimes:
        janela_inicio = datetime.fromtimestamp(min(mtimes), tz=timezone.utc).isoformat()
        janela_fim = datetime.fromtimestamp(max(mtimes), tz=timezone.utc).isoformat()

    produtos, global_recs = gerar_modelo(
        cestas,
        args.minimo_coocorrencia,
        args.limite_por_produto,
        args.limite_global,
    )

    modelo = {
        "treinado_em": treinado_em,
        "janela_dados_inicio": janela_inicio,
        "janela_dados_fim": janela_fim,
        "versao_modelo": f"recomendacoes-coocorrencia-{datetime.now(timezone.utc).strftime('%Y%m%d%H%M%S')}",
        "amostras": len(cestas),
        "minimo_coocorrencia": args.minimo_coocorrencia,
        "limite_por_produto": args.limite_por_produto,
        "limite_global": args.limite_global,
        "produtos": produtos,
        "global": global_recs,
    }

    with open(args.saida_modelo, "w", encoding="utf-8") as arquivo:
        json.dump(modelo, arquivo, ensure_ascii=False, indent=2)


if __name__ == "__main__":
    main()
