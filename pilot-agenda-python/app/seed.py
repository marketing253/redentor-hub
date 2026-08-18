"""
seed.py — importa os dados reais (Plano de Ação e Biarticulado) na
primeira vez que o serviço roda, se as tabelas ainda estiverem
vazias. Não sobrescreve nada em execuções seguintes.
"""
import json
import os
from datetime import datetime
from sqlalchemy.orm import Session

from app import models
from app.seguranca import hash_senha

_DIR = os.path.join(os.path.dirname(__file__), "seed_data")
_ADMIN_SENHA_INICIAL = "redentor@2026"


def _data_ou_none(s):
    if not s:
        return None
    try:
        return datetime.strptime(s, "%Y-%m-%d").date()
    except ValueError:
        return None


def rodar_seed(db: Session):
    if db.query(models.PlanoItem).count() == 0:
        with open(os.path.join(_DIR, "plano_seed.json"), encoding="utf-8") as f:
            dados = json.load(f)
        for item in dados.get("itens", []):
            db.add(models.PlanoItem(
                num=item.get("num"),
                atividade=item.get("atividade", ""),
                quem=item.get("quem") or None,
                oque=item.get("oque") or None,
                como=item.get("como") or None,
                custo=float(item.get("custo") or 0),
                eficacia=item.get("eficacia") or None,
                pct=int(item.get("pct") or 0),
            ))
        db.commit()

    if db.query(models.BiartRegistro).count() == 0:
        with open(os.path.join(_DIR, "biart_seed.json"), encoding="utf-8") as f:
            dados = json.load(f)
        for r in dados:
            db.add(models.BiartRegistro(
                cpd=r.get("cpd") or None,
                nome=r.get("nome", ""),
                sexo=r.get("sexo") or None,
                data=_data_ou_none(r.get("data")),
                autorizacao=r.get("autorizacao") or None,
                processo=r.get("processo") or None,
            ))
        db.commit()

    if db.query(models.Acidente).count() == 0:
        with open(os.path.join(_DIR, "acidentes_seed.json"), encoding="utf-8") as f:
            dados = json.load(f)
        F = dados.get("F", {})
        keys = F.get("keys", [])
        # "keys" são campos categóricos: cada um tem um F[chave] (índice por
        # registro) e um F[chave+"_vals"] (lista de valores únicos) — assim
        # o arquivo original não repetia o mesmo texto milhares de vezes.
        # culpado/evitavel/vitima/perdakm já vêm como 0/1 direto por registro.
        total = len(F.get("culpado", []))
        for i in range(total):
            campos = {}
            for k in keys:
                idxs = F.get(k, [])
                vals = F.get(k + "_vals", [])
                idx = idxs[i] if i < len(idxs) else None
                campos[k] = vals[idx] if (idx is not None and 0 <= idx < len(vals)) else None
            db.add(models.Acidente(
                data=_data_ou_none(campos.get("data")),
                colaborador=campos.get("colaborador"),
                equipamento=campos.get("equipamento"),
                linha=campos.get("linha"),
                atendente=campos.get("atendente"),
                avaliacao=campos.get("avaliacao"),
                evitado=campos.get("evitado"),
                clima=campos.get("clima"),
                tipo_dia=campos.get("tipo_dia"),
                hora=campos.get("hora"),
                culpado=bool(F.get("culpado", [])[i]) if i < len(F.get("culpado", [])) else False,
                evitavel=bool(F.get("evitavel", [])[i]) if i < len(F.get("evitavel", [])) else False,
                vitima=bool(F.get("vitima", [])[i]) if i < len(F.get("vitima", [])) else False,
                perdakm=bool(F.get("perdakm", [])[i]) if i < len(F.get("perdakm", [])) else False,
            ))
            if i % 500 == 0:
                db.flush()
        db.commit()

    if db.query(models.AderenciaRegistro).count() == 0:
        with open(os.path.join(_DIR, "aderencia_seed.json"), encoding="utf-8") as f:
            dados = json.load(f)
        groups = dados.get("groups", [])
        lines = dados.get("lines", [])
        line_group = dados.get("lineGroup", [])
        idents = dados.get("idents", [])
        # rows: [identIdx, lineIdx, total, igual, diaIdx, ano, mes]
        for i, r in enumerate(dados.get("rows", [])):
            ident_idx, line_idx, total, igual, dia, ano, mes = r
            db.add(models.AderenciaRegistro(
                identificacao=idents[ident_idx],
                linha=lines[line_idx],
                grupo=groups[line_group[line_idx]],
                dia=dia, mes=mes, ano=ano, total=total, igual=igual,
            ))
            if i % 500 == 0:
                db.flush()
        db.commit()

    if db.query(models.Usuario).count() == 0:
        db.add(models.Usuario(
            nome="Administrador",
            usuario="admin",
            senha_hash=hash_senha(_ADMIN_SENHA_INICIAL),
            role="admin",
            ativo=True,
        ))
        db.commit()
