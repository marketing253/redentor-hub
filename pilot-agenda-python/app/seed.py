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

_DIR = os.path.join(os.path.dirname(__file__), "seed_data")


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
