#!/usr/bin/env python3
import argparse
import json
import math
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Dict, Iterable, List, Tuple


EVENTOS_ABANDONO = {"tentativa_saida", "aba_oculta", "inatividade"}
EVENTOS_PREDICAO = {"tentativa_saida", "aba_oculta", "inatividade"}


def ler_eventos(logs_dir: Path) -> Iterable[Dict]:
    for arquivo in sorted(logs_dir.glob("eventos-abandono-*.jsonl")):
        with arquivo.open("r", encoding="utf-8") as f:
            for linha in f:
                linha = linha.strip()
                if not linha:
                    continue
                try:
                    yield json.loads(linha)
                except json.JSONDecodeError:
                    continue


def parse_timestamp(valor: str) -> datetime | None:
    if not valor:
        return None
    try:
        if valor.endswith("Z"):
            valor = valor.replace("Z", "+00:00")
        ts = datetime.fromisoformat(valor)
        if ts.tzinfo is None:
            ts = ts.replace(tzinfo=timezone.utc)
        return ts
    except ValueError:
        return None


def limpar_logs_antigos(logs_dir: Path, dias: int) -> List[Path]:
    if dias <= 0:
        return []
    limite = datetime.now(timezone.utc).date() - timedelta(days=dias)
    removidos: List[Path] = []
    for arquivo in logs_dir.glob("eventos-abandono-*.jsonl"):
        nome = arquivo.stem
        partes = nome.split("eventos-abandono-")
        if len(partes) != 2:
            continue
        try:
            data_log = datetime.strptime(partes[1], "%Y-%m-%d").date()
        except ValueError:
            continue
        if data_log < limite:
            try:
                arquivo.unlink()
                removidos.append(arquivo)
            except OSError:
                continue
    return removidos


def obter_chave_sessao(evento: Dict) -> str:
    sessao = (evento.get("sessaoId") or "").strip()
    if sessao:
        return f"sessao:{sessao}"
    cd_pessoa = (evento.get("cdPessoa") or "").strip()
    data = ((evento.get("timestamp") or "")[:10]).strip()
    if cd_pessoa:
        return f"cliente:{cd_pessoa}:{data}"
    if data:
        return f"anon:{data}"
    return "anon"


def normalizar_valor(valor, minimo=0.0):
    try:
        return float(valor)
    except (TypeError, ValueError):
        return minimo


def carregar_schema(schema_path: Path) -> Tuple[List[str], Dict[str, Tuple[float, float]], Dict[str, Dict]]:
    if not schema_path.is_file():
        raise SystemExit(f"Schema não encontrado: {schema_path}")
    try:
        schema = json.loads(schema_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise SystemExit(f"Schema inválido: {exc}") from exc
    if not isinstance(schema, dict):
        raise SystemExit("Schema inválido: formato inesperado.")
    for chave in ("features", "limites", "mapeamentos"):
        if chave not in schema:
            raise SystemExit(f"Schema incompleto: chave '{chave}' ausente.")
    features = schema.get("features")
    limites_raw = schema.get("limites")
    mapeamentos = schema.get("mapeamentos")
    if not isinstance(features, list) or not features:
        raise SystemExit("Schema inválido: 'features' deve ser uma lista.")
    if not isinstance(limites_raw, dict) or not isinstance(mapeamentos, dict):
        raise SystemExit("Schema inválido: 'limites' ou 'mapeamentos' inválidos.")
    limites: Dict[str, Tuple[float, float]] = {}
    for feature in features:
        limite = limites_raw.get(feature)
        if not isinstance(limite, dict) or "min" not in limite or "max" not in limite:
            raise SystemExit(f"Schema incompleto: limites ausentes para '{feature}'.")
        limites[feature] = (float(limite["min"]), float(limite["max"]))
    return features, limites, mapeamentos


def aplicar_limites(valor, limite: Tuple[float, float]) -> float:
    minimo, maximo = limite
    valor_normalizado = normalizar_valor(valor, minimo)
    if valor_normalizado < minimo:
        return minimo
    if valor_normalizado > maximo:
        return maximo
    return valor_normalizado


def obter_valor_mapeado(evento: Dict, sessao: Dict, mapeamento: Dict) -> float:
    valor = None
    campo = mapeamento.get("campo")
    if campo:
        valor = evento.get(campo)
    if (valor is None or valor == "") and mapeamento.get("fallback_sessao"):
        valor = sessao.get(mapeamento["fallback_sessao"])
    return normalizar_valor(valor, 0.0)


def extrair_features(
    evento: Dict,
    sessao: Dict,
    converteu: bool,
    inicio_sessao: datetime | None,
    features_modelo: List[str],
    limites: Dict[str, Tuple[float, float]],
    mapeamentos: Dict[str, Dict],
) -> Tuple[List[float], int]:
    nome = (evento.get("evento") or "").lower()
    if nome.startswith("popup_") or nome.startswith("conversao_"):
        return [], -1
    if nome not in EVENTOS_PREDICAO:
        return [], -1

    valores_features: Dict[str, float] = {}
    for feature in features_modelo:
        mapeamento = mapeamentos.get(feature, {})
        if "evento" in mapeamento:
            valor = 1.0 if nome == mapeamento["evento"] else 0.0
        elif mapeamento.get("derivado") == "valor_carrinho/quantidade_itens":
            valor_carrinho = valores_features.get("valor_carrinho", 0.0)
            quantidade_itens = valores_features.get("quantidade_itens", 0.0)
            valor = valor_carrinho / max(1.0, quantidade_itens)
        else:
            valor = obter_valor_mapeado(evento, sessao, mapeamento)
            if feature == "tempo_desde_inicio_ms" and valor <= 0.0:
                timestamp = parse_timestamp(evento.get("timestamp") or "")
                if inicio_sessao and timestamp:
                    valor = max(0.0, (timestamp - inicio_sessao).total_seconds() * 1000)
        valores_features[feature] = aplicar_limites(valor, limites[feature])

    features = [valores_features[feature] for feature in features_modelo]

    label = 0 if converteu else 1
    return features, label


def normalizar_label(valor) -> int | None:
    if valor is None:
        return None
    if isinstance(valor, bool):
        return 1 if valor else 0
    try:
        label = int(valor)
    except (TypeError, ValueError):
        return None
    return 1 if label == 1 else 0


def rotular_por_janela(registro: Dict, janela_horas: float) -> int:
    timestamp = parse_timestamp(registro.get("timestamp") or "")
    conversao_em = (
        parse_timestamp(registro.get("conversaoEm") or "")
        or parse_timestamp(registro.get("conversao_em") or "")
        or parse_timestamp(registro.get("converteuEm") or "")
    )
    if not timestamp or not conversao_em:
        return 1
    diferenca_horas = (conversao_em - timestamp).total_seconds() / 3600
    return 0 if 0 <= diferenca_horas <= janela_horas else 1


def montar_features_dataset(
    registro: Dict,
    features_modelo: List[str],
    limites: Dict[str, Tuple[float, float]],
) -> List[float] | None:
    features_raw = registro.get("features")
    if isinstance(features_raw, list):
        if len(features_raw) != len(features_modelo):
            return None
        return [
            aplicar_limites(valor, limites[nome])
            for nome, valor in zip(features_modelo, features_raw)
        ]
    if isinstance(features_raw, dict):
        return [aplicar_limites(features_raw.get(nome), limites[nome]) for nome in features_modelo]
    return None


def carregar_dataset_rotulado(
    caminho: Path,
    janela_horas: float,
    features_modelo: List[str],
    limites: Dict[str, Tuple[float, float]],
) -> List[Dict]:
    amostras: List[Dict] = []
    with caminho.open("r", encoding="utf-8") as f:
        for linha in f:
            linha = linha.strip()
            if not linha:
                continue
            try:
                registro = json.loads(linha)
            except json.JSONDecodeError:
                continue
            if not isinstance(registro, dict):
                continue
            features = montar_features_dataset(registro, features_modelo, limites)
            if features is None:
                continue
            label = normalizar_label(registro.get("label"))
            if label is None:
                label = rotular_por_janela(registro, janela_horas)
            inicio = parse_timestamp(registro.get("timestamp") or "") or datetime.min.replace(tzinfo=timezone.utc)
            sessao = (registro.get("sessaoId") or "").strip()
            cd_pessoa = (registro.get("cdPessoa") or "").strip()
            if sessao:
                chave = f"sessao:{sessao}"
            elif cd_pessoa:
                chave = f"cliente:{cd_pessoa}:{inicio.date()}"
            else:
                chave = f"anon:{inicio.date()}"
            amostras.append(
                {
                    "chave": chave,
                    "inicio": inicio,
                    "features": features,
                    "label": label,
                }
            )
    return amostras


def calcular_media_std(vetores: List[List[float]]) -> Tuple[List[float], List[float]]:
    if not vetores:
        return [], []
    colunas = list(zip(*vetores))
    medias = [sum(col) / len(col) for col in colunas]
    stds = []
    for idx, col in enumerate(colunas):
        variancia = sum((x - medias[idx]) ** 2 for x in col) / max(1, len(col) - 1)
        stds.append(math.sqrt(variancia) if variancia > 0 else 1.0)
    return medias, stds


def padronizar(vetor: List[float], medias: List[float], stds: List[float]) -> List[float]:
    return [(valor - medias[idx]) / stds[idx] for idx, valor in enumerate(vetor)]


def sigmoid(z: float) -> float:
    return 1.0 / (1.0 + math.exp(-z))


def treinar_logistic_regression(
    X: List[List[float]],
    y: List[int],
    pesos_classe: Dict[int, float],
    epochs: int = 400,
    taxa_aprendizado: float = 0.05,
) -> List[float]:
    if not X:
        return []
    n_features = len(X[0])
    pesos = [0.0] * (n_features + 1)  # bias + pesos

    for _ in range(epochs):
        grad = [0.0] * len(pesos)
        for linha, alvo in zip(X, y):
            peso_amostra = pesos_classe.get(alvo, 1.0)
            z = pesos[0]
            for j, valor in enumerate(linha, start=1):
                z += pesos[j] * valor
            pred = sigmoid(z)
            erro = pred - alvo
            grad[0] += erro * peso_amostra
            for j, valor in enumerate(linha, start=1):
                grad[j] += erro * valor * peso_amostra

        for j in range(len(pesos)):
            pesos[j] -= taxa_aprendizado * grad[j] / max(1, len(X))

    return pesos


def calcular_pesos_classe(labels: List[int]) -> Dict[int, float]:
    total = len(labels)
    if total == 0:
        return {0: 1.0, 1: 1.0}
    positivos = sum(1 for y in labels if y == 1)
    negativos = total - positivos
    if positivos == 0 or negativos == 0:
        return {0: 1.0, 1: 1.0}
    peso_positivo = total / (2 * positivos)
    peso_negativo = total / (2 * negativos)
    return {0: peso_negativo, 1: peso_positivo}


def calcular_metricas(scores: List[float], labels: List[int], threshold: float) -> Dict[str, float]:
    tp = fp = tn = fn = 0
    for score, label in zip(scores, labels):
        pred = 1 if score >= threshold else 0
        if pred == 1 and label == 1:
            tp += 1
        elif pred == 1 and label == 0:
            fp += 1
        elif pred == 0 and label == 0:
            tn += 1
        else:
            fn += 1
    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
    return {"precision": precision, "recall": recall, "f1": f1}


def calcular_accuracy(scores: List[float], labels: List[int], threshold: float) -> float:
    total = len(labels)
    if total == 0:
        return 0.0
    acertos = sum(1 for score, label in zip(scores, labels) if (score >= threshold) == (label == 1))
    return acertos / total


def calcular_auc(scores: List[float], labels: List[int]) -> float:
    pares = list(zip(scores, labels))
    positivos = sum(1 for _, label in pares if label == 1)
    negativos = len(pares) - positivos
    if positivos == 0 or negativos == 0:
        return 0.0
    pares.sort(key=lambda item: item[0])
    rank = 1
    soma_ranks_positivos = 0.0
    idx = 0
    while idx < len(pares):
        score_atual = pares[idx][0]
        j = idx
        while j < len(pares) and pares[j][0] == score_atual:
            j += 1
        rank_medio = (rank + (rank + (j - idx) - 1)) / 2
        for k in range(idx, j):
            if pares[k][1] == 1:
                soma_ranks_positivos += rank_medio
        rank += j - idx
        idx = j
    auc = (soma_ranks_positivos - (positivos * (positivos + 1) / 2)) / (positivos * negativos)
    return auc


def calibrar_threshold(scores: List[float], labels: List[int]) -> Tuple[float, Dict[str, float]]:
    melhores_metricas = {"precision": 0.0, "recall": 0.0, "f1": 0.0}
    melhor_threshold = 0.55
    candidatos = sorted(set(scores))
    if not candidatos:
        return melhor_threshold, melhores_metricas
    for threshold in candidatos:
        metricas = calcular_metricas(scores, labels, threshold)
        if metricas["f1"] > melhores_metricas["f1"]:
            melhores_metricas = metricas
            melhor_threshold = threshold
    return melhor_threshold, melhores_metricas


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--logs-dir", required=True)
    parser.add_argument("--saida-modelo", required=True)
    parser.add_argument("--limpar-logs-dias", type=int, default=30)
    parser.add_argument("--permitir-sem-dados", action="store_true")
    parser.add_argument("--dataset-rotulado")
    parser.add_argument("--janela-horas", type=float, default=6.0)
    args = parser.parse_args()

    logs_dir = Path(args.logs_dir)
    if not logs_dir.exists():
        raise SystemExit(f"Diretório de logs não encontrado: {logs_dir}")

    schema_path = Path(__file__).resolve().parent / "IASchemaAbandono.json"
    features_modelo, limites_features, mapeamentos = carregar_schema(schema_path)

    amostras_por_sessao: List[Dict] = []
    if args.dataset_rotulado:
        dataset_path = Path(args.dataset_rotulado)
        if not dataset_path.is_file():
            raise SystemExit(f"Dataset rotulado não encontrado: {dataset_path}")
        registros = carregar_dataset_rotulado(
            dataset_path,
            args.janela_horas,
            features_modelo,
            limites_features,
        )
        agrupado: Dict[str, Dict] = {}
        for registro in registros:
            chave = registro["chave"]
            sessao = agrupado.setdefault(
                chave,
                {
                    "inicio": registro["inicio"],
                    "features": [],
                    "labels": [],
                },
            )
            sessao["features"].append(registro["features"])
            sessao["labels"].append(registro["label"])
            if registro["inicio"] < sessao["inicio"]:
                sessao["inicio"] = registro["inicio"]
        amostras_por_sessao = list(agrupado.values())
    else:
        sessoes: Dict[str, List[Dict]] = {}
        for evento in ler_eventos(logs_dir):
            chave = obter_chave_sessao(evento)
            sessoes.setdefault(chave, []).append(evento)

        for eventos in sessoes.values():
            eventos_ordenados = sorted(
                eventos,
                key=lambda ev: parse_timestamp(ev.get("timestamp") or "")
                or datetime.min.replace(tzinfo=timezone.utc),
            )
            inicio_sessao = parse_timestamp(eventos_ordenados[0].get("timestamp") or "")
            conversoes = [
                parse_timestamp(ev.get("timestamp") or "")
                for ev in eventos_ordenados
                if (ev.get("evento") or "").lower().startswith("conversao_")
            ]
            conversoes = [ts for ts in conversoes if ts]
            conversao_em = min(conversoes) if conversoes else None
            converteu = conversao_em is not None
            tempo_ativo_total_ms = max(
                (normalizar_valor(ev.get("tempoAtivoTotalMs"), 0.0) for ev in eventos_ordenados),
                default=0.0,
            )
            profundidade_scroll = max(
                (normalizar_valor(ev.get("profundidadeScroll"), 0.0) for ev in eventos_ordenados),
                default=0.0,
            )
            total_modais_abertos = max(
                (normalizar_valor(ev.get("totalModaisAbertos"), 0.0) for ev in eventos_ordenados),
                default=0.0,
            )
            interacoes_recomendacoes = max(
                (normalizar_valor(ev.get("interacoesRecomendacoes"), 0.0) for ev in eventos_ordenados),
                default=0.0,
            )
            sessao_info = {
                "total_eventos": len(eventos_ordenados),
                "eventos_abandono": sum(
                    1
                    for ev in eventos_ordenados
                    if (ev.get("evento") or "").lower() in EVENTOS_ABANDONO
                ),
                "tempo_ativo_total_ms": tempo_ativo_total_ms,
                "profundidade_scroll": profundidade_scroll,
                "total_modais_abertos": total_modais_abertos,
                "interacoes_recomendacoes": interacoes_recomendacoes,
            }
            features_sessao = []
            labels_sessao = []
            for evento in eventos_ordenados:
                if conversao_em:
                    ts_evento = parse_timestamp(evento.get("timestamp") or "")
                    if ts_evento and ts_evento > conversao_em:
                        continue
                vetor, label = extrair_features(
                    evento,
                    sessao_info,
                    converteu,
                    inicio_sessao,
                    features_modelo,
                    limites_features,
                    mapeamentos,
                )
                if label < 0:
                    continue
                features_sessao.append(vetor)
                labels_sessao.append(label)
            if features_sessao:
                amostras_por_sessao.append(
                    {
                        "inicio": inicio_sessao or datetime.min.replace(tzinfo=timezone.utc),
                        "features": features_sessao,
                        "labels": labels_sessao,
                    }
                )

    if not amostras_por_sessao:
        if args.permitir_sem_dados:
            return 0
        raise SystemExit("Nenhum dado válido encontrado para treino.")

    amostras_por_sessao.sort(key=lambda item: item["inicio"])
    total_sessoes = len(amostras_por_sessao)
    corte = max(1, int(total_sessoes * 0.8))
    if total_sessoes > 1 and corte >= total_sessoes:
        corte = total_sessoes - 1
    treino_sessoes = amostras_por_sessao[:corte]
    validacao_sessoes = amostras_por_sessao[corte:]

    features_treino = [v for sessao in treino_sessoes for v in sessao["features"]]
    labels_treino = [l for sessao in treino_sessoes for l in sessao["labels"]]
    features_validacao = [v for sessao in validacao_sessoes for v in sessao["features"]]
    labels_validacao = [l for sessao in validacao_sessoes for l in sessao["labels"]]

    medias, stds = calcular_media_std(features_treino)
    features_treino_norm = [padronizar(v, medias, stds) for v in features_treino]
    features_validacao_norm = [padronizar(v, medias, stds) for v in features_validacao]

    pesos_classe = calcular_pesos_classe(labels_treino)
    pesos = treinar_logistic_regression(features_treino_norm, labels_treino, pesos_classe)
    scores_validacao = []
    for linha in features_validacao_norm:
        z = pesos[0]
        for j, valor in enumerate(linha, start=1):
            z += pesos[j] * valor
        scores_validacao.append(sigmoid(z))
    threshold, metricas_validacao = calibrar_threshold(scores_validacao, labels_validacao)
    metricas_validacao["accuracy"] = calcular_accuracy(scores_validacao, labels_validacao, threshold)
    metricas_validacao["auc"] = calcular_auc(scores_validacao, labels_validacao)

    scores_treino = []
    for linha in features_treino_norm:
        z = pesos[0]
        for j, valor in enumerate(linha, start=1):
            z += pesos[j] * valor
        scores_treino.append(sigmoid(z))
    metricas_treino = calcular_metricas(scores_treino, labels_treino, threshold)
    metricas_treino["accuracy"] = calcular_accuracy(scores_treino, labels_treino, threshold)
    metricas_treino["auc"] = calcular_auc(scores_treino, labels_treino)

    agora = datetime.now(timezone.utc)
    inicios_sessoes = [sessao["inicio"] for sessao in amostras_por_sessao if sessao.get("inicio")]
    janela_inicio = min(inicios_sessoes) if inicios_sessoes else None
    janela_fim = max(inicios_sessoes) if inicios_sessoes else None

    positivos_treino = sum(1 for label in labels_treino if label == 1)
    negativos_treino = len(labels_treino) - positivos_treino
    positivos_validacao = sum(1 for label in labels_validacao if label == 1)
    negativos_validacao = len(labels_validacao) - positivos_validacao
    total_eventos = len(labels_treino) + len(labels_validacao)
    total_positivos = positivos_treino + positivos_validacao
    total_negativos = total_eventos - total_positivos
    taxa_positivo = (total_positivos / total_eventos) if total_eventos else 0.0

    modelo = {
        "treinado_em": agora.isoformat(),
        "data_treino": agora.isoformat(),
        "janela_dados_inicio": janela_inicio.isoformat() if janela_inicio else None,
        "janela_dados_fim": janela_fim.isoformat() if janela_fim else None,
        "taxa_positivo": taxa_positivo,
        "versao_modelo": agora.strftime("abandono-%Y%m%d%H%M%S"),
        "features": features_modelo,
        "features_utilizadas": features_modelo,
        "medias": medias,
        "stds": stds,
        "pesos": pesos,
        "threshold": threshold,
        "metricas": metricas_validacao,
        "metricas_treino": metricas_treino,
        "metricas_validacao": metricas_validacao,
        "amostras": len(labels_treino),
        "amostras_validacao": len(labels_validacao),
        "num_amostras": total_eventos,
        "sessoes_treino": len(treino_sessoes),
        "sessoes_validacao": len(validacao_sessoes),
        "split": "temporal_80_20",
        "contagem_eventos_classe": {
            "treino": {"0": negativos_treino, "1": positivos_treino},
            "validacao": {"0": negativos_validacao, "1": positivos_validacao},
            "total": {"0": total_negativos, "1": total_positivos},
        },
    }
    if args.dataset_rotulado:
        modelo["janela_rotulo_horas"] = args.janela_horas

    saida = Path(args.saida_modelo)
    saida.parent.mkdir(parents=True, exist_ok=True)
    saida.write_text(json.dumps(modelo, ensure_ascii=False, indent=2), encoding="utf-8")
    limpar_logs_antigos(logs_dir, args.limpar_logs_dias)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
