#!/usr/bin/env python3
import argparse
import json
import math
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple
import hashlib

ALERTA_QUEDA_ACURACIA = 0.05
ALERTA_QUEDA_AUC = 0.05
ALERTA_PSI = 0.2
DRIFT_BASE_SEMANAS = 4


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


def ler_jsonl(arquivos: Iterable[Path]) -> Iterable[Dict]:
    for arquivo in arquivos:
        with arquivo.open("r", encoding="utf-8") as f:
            for linha in f:
                linha = linha.strip()
                if not linha:
                    continue
                try:
                    dado = json.loads(linha)
                except json.JSONDecodeError:
                    continue
                if isinstance(dado, dict):
                    yield dado


def limpar_inferencias_antigas(logs_dir: Path, dias: int) -> Dict[str, object]:
    resultado: Dict[str, object] = {
        "dias": dias,
        "limite": None,
        "aplicada_em": datetime.now(timezone.utc).isoformat(),
        "total_removidos": 0,
        "arquivos_removidos": [],
    }
    if dias <= 0:
        return resultado
    limite = datetime.now(timezone.utc).date() - timedelta(days=dias)
    resultado["limite"] = limite.isoformat()
    removidos: List[str] = []
    for arquivo in logs_dir.glob("inferencias-abandono-*.jsonl"):
        nome = arquivo.stem
        partes = nome.split("inferencias-abandono-")
        if len(partes) != 2:
            continue
        try:
            data_log = datetime.strptime(partes[1], "%Y-%m-%d").date()
        except ValueError:
            continue
        if data_log < limite:
            try:
                arquivo.unlink()
                removidos.append(arquivo.name)
            except OSError:
                continue
    removidos.sort()
    resultado["arquivos_removidos"] = removidos
    resultado["total_removidos"] = len(removidos)
    return resultado


def obter_chave_sessao(registro: Dict) -> str:
    sessao = (registro.get("sessaoId") or "").strip()
    if sessao:
        return f"sessao:{sessao}"
    cd_pessoa = (registro.get("cdPessoa") or "").strip()
    data = ((registro.get("timestamp") or "")[:10]).strip()
    if cd_pessoa:
        return f"cliente:{cd_pessoa}:{data}"
    if data:
        return f"anon:{data}"
    return "anon"


def safe_div(numerador: int, denominador: int) -> float:
    return round(numerador / denominador, 4) if denominador else 0.0


def calcular_matriz_confusao(itens: Iterable[Dict]) -> Dict[str, float]:
    tp = fp = tn = fn = 0
    total = 0
    for item in itens:
        if not item.get("avaliada"):
            continue
        total += 1
        label = 1 if item.get("label_abandono") else 0
        pred = 1 if item.get("predicao_popup") else 0
        if pred == 1 and label == 1:
            tp += 1
        elif pred == 1 and label == 0:
            fp += 1
        elif pred == 0 and label == 0:
            tn += 1
        else:
            fn += 1

    precisao = safe_div(tp, tp + fp)
    recall = safe_div(tp, tp + fn)
    f1 = round((2 * precisao * recall) / (precisao + recall), 4) if (precisao + recall) else 0.0
    acuracia = safe_div(tp + tn, total)

    return {
        "tp": tp,
        "fp": fp,
        "tn": tn,
        "fn": fn,
        "precision": precisao,
        "recall": recall,
        "f1": f1,
        "accuracy": acuracia,
        "amostras": total,
    }


def calcular_estatisticas_classes(itens: Iterable[Dict]) -> Dict[str, int]:
    positivos = 0
    negativos = 0
    for item in itens:
        if not item.get("avaliada"):
            continue
        if item.get("label_abandono"):
            positivos += 1
        else:
            negativos += 1
    return {
        "positivos": positivos,
        "negativos": negativos,
        "total": positivos + negativos,
    }


def calcular_auc(itens: Iterable[Dict]) -> Optional[float]:
    scores_labels: List[Tuple[float, int]] = []
    for item in itens:
        if not item.get("avaliada"):
            continue
        score = item.get("score_referencia")
        if score is None:
            continue
        try:
            valor = float(score)
        except (TypeError, ValueError):
            continue
        label = 1 if item.get("label_abandono") else 0
        scores_labels.append((valor, label))

    if not scores_labels:
        return None

    positivos = sum(1 for _, label in scores_labels if label == 1)
    negativos = len(scores_labels) - positivos
    if positivos == 0 or negativos == 0:
        return None

    scores_labels.sort(key=lambda item: item[0])
    rank = 1
    soma_ranks_pos = 0.0
    idx = 0
    total = len(scores_labels)
    while idx < total:
        score_atual = scores_labels[idx][0]
        j = idx
        while j < total and scores_labels[j][0] == score_atual:
            j += 1
        media_rank = (rank + (rank + (j - idx) - 1)) / 2.0
        for k in range(idx, j):
            if scores_labels[k][1] == 1:
                soma_ranks_pos += media_rank
        rank += (j - idx)
        idx = j

    auc = (soma_ranks_pos - (positivos * (positivos + 1) / 2.0)) / (positivos * negativos)
    return round(auc, 4)


def agrupar_por_semana(sessoes: Dict[str, Dict]) -> Dict[str, List[Dict]]:
    agrupado = defaultdict(list)
    for info in sessoes.values():
        inicio = info.get("inicio")
        if not isinstance(inicio, datetime):
            continue
        semana = inicio.isocalendar()
        chave = f"{semana.year}-W{semana.week:02d}"
        agrupado[chave].append(info)
    return dict(sorted(agrupado.items()))


def gerar_histograma_scores(scores: Iterable[float], bins: int = 10) -> Dict[str, Dict[str, int]]:
    faixas = {}
    limites = [i / bins for i in range(bins + 1)]
    labels = []
    for i in range(bins):
        inicio = limites[i]
        fim = limites[i + 1]
        label = f"{inicio:.1f}-{fim:.1f}"
        labels.append(label)
        faixas[label] = 0

    total = 0
    for score in scores:
        try:
            valor = float(score)
        except (TypeError, ValueError):
            continue
        if valor < 0:
            valor = 0.0
        if valor > 1:
            valor = 1.0
        if valor == 1.0:
            idx = bins - 1
        else:
            idx = int(valor * bins)
            idx = min(max(idx, 0), bins - 1)
        faixas[labels[idx]] += 1
        total += 1

    return {"total": total, "faixas": faixas}


def gerar_distribuicao_scores(scores: Iterable[float], bins: int = 10) -> List[float]:
    hist = gerar_histograma_scores(scores, bins)
    total = hist["total"]
    faixas = list(hist["faixas"].values())
    if total <= 0:
        return [0.0 for _ in faixas]
    return [valor / total for valor in faixas]


def calcular_psi(distribuicao_base: List[float], distribuicao_atual: List[float], eps: float = 1e-6) -> float:
    if len(distribuicao_base) != len(distribuicao_atual):
        return 0.0
    soma = 0.0
    for base, atual in zip(distribuicao_base, distribuicao_atual):
        base = max(base, eps)
        atual = max(atual, eps)
        soma += (atual - base) * (math.log(atual / base))
    return round(soma, 4)


def calcular_calibracao(sessoes: Iterable[Dict], bins: int = 10) -> Dict[str, object]:
    total = 0
    soma_brier = 0.0
    faixas = []
    limites = [i / bins for i in range(bins + 1)]
    for i in range(bins):
        inicio = limites[i]
        fim = limites[i + 1]
        faixas.append(
            {
                "faixa": f"{inicio:.1f}-{fim:.1f}",
                "amostras": 0,
                "media_score": 0.0,
                "taxa_abandono": 0.0,
            }
        )

    for sessao in sessoes:
        if not sessao.get("avaliada"):
            continue
        score = sessao.get("score_referencia")
        if score is None:
            continue
        try:
            valor = float(score)
        except (TypeError, ValueError):
            continue
        if valor < 0:
            valor = 0.0
        if valor > 1:
            valor = 1.0
        label = 1 if sessao.get("label_abandono") else 0
        soma_brier += (valor - label) ** 2
        total += 1
        if valor == 1.0:
            idx = bins - 1
        else:
            idx = int(valor * bins)
            idx = min(max(idx, 0), bins - 1)
        faixa = faixas[idx]
        faixa["amostras"] += 1
        faixa["media_score"] += valor
        faixa["taxa_abandono"] += label

    for faixa in faixas:
        amostras = faixa["amostras"]
        if amostras:
            faixa["media_score"] = round(faixa["media_score"] / amostras, 4)
            faixa["taxa_abandono"] = round(faixa["taxa_abandono"] / amostras, 4)
        else:
            faixa["media_score"] = 0.0
            faixa["taxa_abandono"] = 0.0

    brier = round(soma_brier / total, 4) if total else 0.0
    return {"amostras": total, "brier_score": brier, "bins": faixas}


def calcular_metricas_por_semana(sessoes: Dict[str, Dict]) -> Dict[str, Dict[str, object]]:
    metricas = {}
    for semana, itens in agrupar_por_semana(sessoes).items():
        matriz = calcular_matriz_confusao(itens)
        total = matriz["amostras"]
        popups = sum(1 for item in itens if item.get("avaliada") and item.get("predicao_popup"))
        scores = [score for item in itens for score in item.get("scores", [])]
        auc = calcular_auc(itens)
        classes = calcular_estatisticas_classes(itens)
        metricas[semana] = {
            "amostras": total,
            "taxa_acerto": matriz["accuracy"],
            "taxa_popup": safe_div(popups, total),
            "precision": matriz["precision"],
            "recall": matriz["recall"],
            "f1": matriz["f1"],
            "auc": auc,
            "classes": classes,
            "matriz_confusao": {
                "tp": matriz["tp"],
                "fp": matriz["fp"],
                "tn": matriz["tn"],
                "fn": matriz["fn"],
            },
            "scores": gerar_histograma_scores(scores),
        }
    return metricas


def normalizar_segmento(registro: Dict) -> str:
    segmento_chave = (registro.get("segmento_chave") or "").strip()
    if segmento_chave:
        return segmento_chave
    segmento = registro.get("segmento")
    if isinstance(segmento, str):
        segmento = segmento.strip()
        return segmento or "nao_informado"
    if isinstance(segmento, dict):
        partes = []
        for chave in sorted(segmento.keys()):
            valor = segmento.get(chave)
            if valor is None:
                continue
            texto = str(valor).strip()
            if not texto:
                continue
            partes.append(f"{chave}:{texto.lower()}")
        return "|".join(partes) if partes else "nao_informado"
    return "nao_informado"


def calcular_metricas_por_segmento(sessoes: Dict[str, Dict]) -> Dict[str, Dict[str, object]]:
    agrupado = defaultdict(list)
    for info in sessoes.values():
        segmento = info.get("segmento") or "nao_informado"
        agrupado[segmento].append(info)

    resultado = {}
    for segmento, itens in sorted(agrupado.items()):
        matriz = calcular_matriz_confusao(itens)
        total = matriz["amostras"]
        popups = sum(1 for item in itens if item.get("avaliada") and item.get("predicao_popup"))
        scores = [score for item in itens for score in item.get("scores", [])]
        resultado[segmento] = {
            "amostras": total,
            "taxa_acerto": matriz["accuracy"],
            "taxa_popup": safe_div(popups, total),
            "precision": matriz["precision"],
            "recall": matriz["recall"],
            "f1": matriz["f1"],
            "matriz_confusao": {
                "tp": matriz["tp"],
                "fp": matriz["fp"],
                "tn": matriz["tn"],
                "fn": matriz["fn"],
            },
            "scores": gerar_histograma_scores(scores),
        }
    return resultado


def registrar_alerta(logs_dir: Path, alerta: Dict[str, object]) -> None:
    logs_dir.mkdir(parents=True, exist_ok=True)
    arquivo = logs_dir / f"alertas-abandono-{datetime.now(timezone.utc).date().isoformat()}.jsonl"
    payload = {"timestamp": datetime.now(timezone.utc).isoformat(), **alerta}
    with arquivo.open("a", encoding="utf-8") as f:
        f.write(json.dumps(payload, ensure_ascii=False) + "\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--logs-dir", required=True)
    parser.add_argument("--saida-metricas", required=True)
    parser.add_argument("--janela-horas", type=float, default=24.0)
    parser.add_argument("--limpar-inferencias-dias", type=int, default=30)
    args = parser.parse_args()

    logs_dir = Path(args.logs_dir)
    if not logs_dir.exists():
        raise SystemExit(f"Diretório de logs não encontrado: {logs_dir}")

    retencao_inferencias = limpar_inferencias_antigas(logs_dir, args.limpar_inferencias_dias)
    inferencias = list(ler_jsonl(sorted(logs_dir.glob("inferencias-abandono-*.jsonl"))))
    eventos = list(ler_jsonl(sorted(logs_dir.glob("eventos-abandono-*.jsonl"))))

    modelo_path = Path(__file__).with_name("IAModeloAbandono.json")
    modelo_info = {}
    if modelo_path.exists():
        conteudo = modelo_path.read_bytes()
        try:
            modelo = json.loads(conteudo.decode("utf-8"))
        except json.JSONDecodeError:
            modelo = None
        versao_hash = hashlib.sha256(conteudo).hexdigest()[:12]
        if isinstance(modelo, dict):
            modelo_info = {
                "arquivo": modelo_path.name,
                "versao": modelo.get("versao", versao_hash),
                "threshold": modelo.get("threshold"),
            }
        else:
            modelo_info = {
                "arquivo": modelo_path.name,
                "versao": versao_hash,
                "threshold": None,
            }

    janela_horas = float(args.janela_horas)
    janela = timedelta(hours=janela_horas)

    sessoes: Dict[str, Dict] = {}
    for inferencia in inferencias:
        chave = obter_chave_sessao(inferencia)
        ts = parse_timestamp(inferencia.get("timestamp") or "")
        segmento = normalizar_segmento(inferencia)
        sessao = sessoes.setdefault(
            chave,
            {
                "inferencias": [],
                "inicio": ts,
                "predicao_popup": False,
                "converteu": False,
                "scores": [],
                "scores_tempo": [],
                "ultima_inferencia": ts,
                "conversoes": [],
                "segmento": segmento,
            },
        )
        if segmento and (not sessao.get("segmento") or sessao.get("segmento") == "nao_informado"):
            sessao["segmento"] = segmento
        if ts and (sessao["inicio"] is None or ts < sessao["inicio"]):
            sessao["inicio"] = ts
        sessao["inferencias"].append(inferencia)
        if ts and (sessao.get("ultima_inferencia") is None or ts > sessao["ultima_inferencia"]):
            sessao["ultima_inferencia"] = ts
        acao = (inferencia.get("acao") or "").lower()
        if acao == "mostrar_popup":
            sessao["predicao_popup"] = True
        score = inferencia.get("score")
        try:
            if score is not None:
                score_float = float(score)
                sessao["scores"].append(score_float)
                sessao["scores_tempo"].append({"timestamp": ts, "score": score_float})
        except (TypeError, ValueError):
            pass
        if not modelo_info.get("threshold"):
            limiar = inferencia.get("limiar")
            try:
                if limiar is not None:
                    modelo_info["threshold"] = float(limiar)
            except (TypeError, ValueError):
                pass

    for evento in eventos:
        nome = (evento.get("evento") or "").lower()
        if not nome.startswith("conversao_"):
            continue
        chave = obter_chave_sessao(evento)
        sessao = sessoes.setdefault(
            chave,
            {
                "inferencias": [],
                "inicio": parse_timestamp(evento.get("timestamp") or ""),
                "predicao_popup": False,
                "converteu": False,
                "scores": [],
                "scores_tempo": [],
                "ultima_inferencia": None,
                "conversoes": [],
                "segmento": "nao_informado",
            },
        )
        conversao_ts = parse_timestamp(evento.get("timestamp") or "")
        if conversao_ts:
            sessao["conversoes"].append(conversao_ts)
        if sessao.get("inicio") is None:
            sessao["inicio"] = conversao_ts

    total_avaliadas = 0
    corretas = 0
    for sessao in sessoes.values():
        if not sessao.get("inferencias"):
            continue
        sessao["avaliada"] = True
        total_avaliadas += 1
        ultima_inferencia = sessao.get("ultima_inferencia")
        conversoes = sessao.get("conversoes", [])
        converteu = False
        if conversoes:
            if isinstance(ultima_inferencia, datetime):
                limite = ultima_inferencia + janela
                for conversao_ts in conversoes:
                    if conversao_ts is None:
                        continue
                    if ultima_inferencia <= conversao_ts <= limite:
                        converteu = True
                        break
            else:
                converteu = True
        sessao["converteu"] = converteu

        score_referencia = None
        scores_tempo = sessao.get("scores_tempo", [])
        if scores_tempo:
            scores_validos = [item for item in scores_tempo if isinstance(item.get("timestamp"), datetime)]
            if scores_validos:
                score_referencia = max(scores_validos, key=lambda item: item["timestamp"])["score"]
            else:
                score_referencia = scores_tempo[-1].get("score")
        elif sessao.get("scores"):
            score_referencia = sessao["scores"][-1]
        sessao["score_referencia"] = score_referencia

        label_abandono = 0 if converteu else 1
        predicao = 1 if sessao.get("predicao_popup") else 0
        sessao["label_abandono"] = label_abandono
        acerto = predicao == label_abandono
        sessao["acerto"] = acerto
        if acerto:
            corretas += 1

    taxa_acerto_total = round(corretas / total_avaliadas, 4) if total_avaliadas else 0.0
    total_popup = sum(1 for sessao in sessoes.values() if sessao.get("inferencias") and sessao.get("predicao_popup"))
    taxa_popup_total = round(total_popup / total_avaliadas, 4) if total_avaliadas else 0.0
    matriz_total = calcular_matriz_confusao(sessoes.values())
    auc_total = calcular_auc(sessoes.values())
    classes_total = calcular_estatisticas_classes(sessoes.values())
    scores_totais = [score for sessao in sessoes.values() for score in sessao.get("scores", [])]
    calibracao = calcular_calibracao(sessoes.values())
    sessoes_por_semana = agrupar_por_semana(sessoes)
    metricas_semanais = calcular_metricas_por_semana(sessoes)

    chaves_semanas = list(metricas_semanais.keys())
    drift_info: Dict[str, object] = {
        "semanas_base": [],
        "semana_atual": None,
        "psi_scores": 0.0,
        "limites": {
            "queda_acuracia": ALERTA_QUEDA_ACURACIA,
            "queda_auc": ALERTA_QUEDA_AUC,
            "psi": ALERTA_PSI,
        },
        "delta_taxa_acerto": 0.0,
        "delta_auc": 0.0,
        "alertas": [],
    }

    if chaves_semanas:
        semana_atual = chaves_semanas[-1]
        base_semanas = chaves_semanas[-(DRIFT_BASE_SEMANAS + 1):-1]
        drift_info["semana_atual"] = semana_atual
        drift_info["semanas_base"] = base_semanas
        if base_semanas:
            base_acuracias = [metricas_semanais[semana]["taxa_acerto"] for semana in base_semanas]
            base_aucs = [metricas_semanais[semana]["auc"] for semana in base_semanas if metricas_semanais[semana]["auc"] is not None]
            media_acuracia = round(sum(base_acuracias) / len(base_acuracias), 4)
            media_auc = round(sum(base_aucs) / len(base_aucs), 4) if base_aucs else None
            atual_acuracia = metricas_semanais[semana_atual]["taxa_acerto"]
            atual_auc = metricas_semanais[semana_atual]["auc"]
            delta_acuracia = round(atual_acuracia - media_acuracia, 4)
            delta_auc = round(atual_auc - media_auc, 4) if (atual_auc is not None and media_auc is not None) else None
            drift_info["delta_taxa_acerto"] = delta_acuracia
            drift_info["delta_auc"] = delta_auc

            scores_base = []
            for semana in base_semanas:
                scores_base.extend(
                    [
                        sessao.get("score_referencia")
                        for sessao in sessoes_por_semana.get(semana, [])
                        if sessao.get("score_referencia") is not None
                    ]
                )
            scores_atual = [
                sessao.get("score_referencia")
                for sessao in sessoes_por_semana.get(semana_atual, [])
                if sessao.get("score_referencia") is not None
            ]

            distrib_base = gerar_distribuicao_scores(scores_base)
            distrib_atual = gerar_distribuicao_scores(scores_atual)
            psi = calcular_psi(distrib_base, distrib_atual)
            drift_info["psi_scores"] = psi

            alertas = []
            if delta_acuracia <= -ALERTA_QUEDA_ACURACIA:
                alertas.append(
                    {
                        "tipo": "queda_acuracia",
                        "mensagem": "Queda significativa na taxa de acerto semanal.",
                        "delta": delta_acuracia,
                        "atual": atual_acuracia,
                        "media_base": media_acuracia,
                    }
                )
            if delta_auc is not None and delta_auc <= -ALERTA_QUEDA_AUC:
                alertas.append(
                    {
                        "tipo": "queda_auc",
                        "mensagem": "Queda significativa no AUC semanal.",
                        "delta": delta_auc,
                        "atual": atual_auc,
                        "media_base": media_auc,
                    }
                )
            if psi >= ALERTA_PSI:
                alertas.append(
                    {
                        "tipo": "drift_scores",
                        "mensagem": "Mudança brusca na distribuição dos scores.",
                        "psi": psi,
                    }
                )

            if alertas:
                drift_info["alertas"] = alertas
                for alerta in alertas:
                    registrar_alerta(
                        logs_dir,
                        {
                            "componente": "metricas_abandono",
                            "semana": semana_atual,
                            **alerta,
                        },
                    )

    resultado = {
        "geradoEm": datetime.now(timezone.utc).isoformat(),
        "janela_horas": janela_horas,
        "amostras": total_avaliadas,
        "taxa_acerto": taxa_acerto_total,
        "taxa_popup": taxa_popup_total,
        "precision": matriz_total["precision"],
        "recall": matriz_total["recall"],
        "f1": matriz_total["f1"],
        "auc": auc_total,
        "classes": classes_total,
        "matriz_confusao": {
            "tp": matriz_total["tp"],
            "fp": matriz_total["fp"],
            "tn": matriz_total["tn"],
            "fn": matriz_total["fn"],
        },
        "scores": gerar_histograma_scores(scores_totais),
        "calibracao": calibracao,
        "modelo": modelo_info,
        "retencao_inferencias": retencao_inferencias,
        "semanas": metricas_semanais,
        "segmentos": calcular_metricas_por_segmento(sessoes),
        "drift": drift_info,
    }

    saida = Path(args.saida_metricas)
    saida.parent.mkdir(parents=True, exist_ok=True)
    saida.write_text(json.dumps(resultado, ensure_ascii=False, indent=2), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
