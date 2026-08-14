"""
main.py — API das ferramentas em Python: Agenda, Chamados, Plano de
Ação e Biarticulado. Um serviço só, um banco só, publicado sozinho a
cada git push.
"""
from typing import List

from fastapi import FastAPI, Depends, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from sqlalchemy.orm import Session
from sqlalchemy import asc, desc

from app.database import engine, get_db, SessionLocal, Base
from app import models, schemas
from app.seed import rodar_seed

Base.metadata.create_all(bind=engine)

# Importa os dados reais (Plano de Ação, Biarticulado) uma única vez,
# no primeiro start — não repete se já tiver dado na tabela.
with SessionLocal() as _db:
    rodar_seed(_db)

app = FastAPI(title="Redentor — Ferramentas (piloto Python)")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/api/saude")
def saude():
    return {"ok": True}


# ══════════════════════════════════════ Agenda ══════════════════
@app.get("/api/eventos", response_model=List[schemas.Evento])
def listar_eventos(usuario: str, db: Session = Depends(get_db)):
    return (
        db.query(models.Evento)
        .filter(models.Evento.usuario == usuario)
        .order_by(asc(models.Evento.data), asc(models.Evento.hora))
        .all()
    )


@app.post("/api/eventos", response_model=schemas.Evento, status_code=201)
def criar_evento(evento: schemas.EventoCriar, db: Session = Depends(get_db)):
    novo = models.Evento(**evento.model_dump())
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/eventos/{evento_id}", response_model=schemas.Evento)
def atualizar_evento(evento_id: int, dados: schemas.EventoAtualizar, db: Session = Depends(get_db)):
    evento = db.query(models.Evento).filter(models.Evento.id == evento_id).first()
    if not evento:
        raise HTTPException(status_code=404, detail="Evento não encontrado.")
    for campo, valor in dados.model_dump(exclude_unset=True).items():
        setattr(evento, campo, valor)
    db.commit()
    db.refresh(evento)
    return evento


@app.delete("/api/eventos/{evento_id}", status_code=204)
def excluir_evento(evento_id: int, db: Session = Depends(get_db)):
    evento = db.query(models.Evento).filter(models.Evento.id == evento_id).first()
    if not evento:
        raise HTTPException(status_code=404, detail="Evento não encontrado.")
    db.delete(evento)
    db.commit()
    return None


# ══════════════════════════════════════ Chamados ════════════════
@app.get("/api/chamados", response_model=List[schemas.Chamado])
def listar_chamados(db: Session = Depends(get_db)):
    return db.query(models.Chamado).order_by(desc(models.Chamado.criado_em)).all()


@app.post("/api/chamados", response_model=schemas.Chamado, status_code=201)
def criar_chamado(c: schemas.ChamadoCriar, db: Session = Depends(get_db)):
    novo = models.Chamado(**c.model_dump())
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/chamados/{chamado_id}", response_model=schemas.Chamado)
def atualizar_chamado(chamado_id: int, dados: schemas.ChamadoAtualizar, db: Session = Depends(get_db)):
    c = db.query(models.Chamado).filter(models.Chamado.id == chamado_id).first()
    if not c:
        raise HTTPException(status_code=404, detail="Chamado não encontrado.")
    for campo, valor in dados.model_dump(exclude_unset=True).items():
        setattr(c, campo, valor)
    db.commit()
    db.refresh(c)
    return c


@app.delete("/api/chamados/{chamado_id}", status_code=204)
def excluir_chamado(chamado_id: int, db: Session = Depends(get_db)):
    c = db.query(models.Chamado).filter(models.Chamado.id == chamado_id).first()
    if not c:
        raise HTTPException(status_code=404, detail="Chamado não encontrado.")
    db.delete(c)
    db.commit()
    return None


@app.get("/api/novidades", response_model=List[schemas.Novidade])
def listar_novidades(db: Session = Depends(get_db)):
    return db.query(models.Novidade).order_by(desc(models.Novidade.data)).limit(200).all()


@app.post("/api/novidades", response_model=schemas.Novidade, status_code=201)
def criar_novidade(n: schemas.NovidadeCriar, db: Session = Depends(get_db)):
    novo = models.Novidade(**n.model_dump())
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


# ══════════════════════════════════════ Plano de Ação ═══════════
@app.get("/api/plano", response_model=List[schemas.PlanoItem])
def listar_plano(db: Session = Depends(get_db)):
    return db.query(models.PlanoItem).order_by(asc(models.PlanoItem.num)).all()


@app.post("/api/plano", response_model=schemas.PlanoItem, status_code=201)
def criar_plano_item(item: schemas.PlanoItemCriar, db: Session = Depends(get_db)):
    ultimo = db.query(models.PlanoItem).order_by(desc(models.PlanoItem.num)).first()
    dados = item.model_dump()
    if not dados.get("num"):
        dados["num"] = (ultimo.num + 1) if ultimo and ultimo.num else 1
    novo = models.PlanoItem(**dados)
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/plano/{item_id}", response_model=schemas.PlanoItem)
def atualizar_plano_item(item_id: int, dados: schemas.PlanoItemAtualizar, db: Session = Depends(get_db)):
    item = db.query(models.PlanoItem).filter(models.PlanoItem.id == item_id).first()
    if not item:
        raise HTTPException(status_code=404, detail="Item não encontrado.")
    for campo, valor in dados.model_dump(exclude_unset=True).items():
        setattr(item, campo, valor)
    db.commit()
    db.refresh(item)
    return item


@app.delete("/api/plano/{item_id}", status_code=204)
def excluir_plano_item(item_id: int, db: Session = Depends(get_db)):
    item = db.query(models.PlanoItem).filter(models.PlanoItem.id == item_id).first()
    if not item:
        raise HTTPException(status_code=404, detail="Item não encontrado.")
    db.delete(item)
    db.commit()
    return None


# ══════════════════════════════════════ Biarticulado ════════════
@app.get("/api/biart", response_model=List[schemas.Biart])
def listar_biart(db: Session = Depends(get_db)):
    return db.query(models.BiartRegistro).order_by(desc(models.BiartRegistro.data)).all()


@app.post("/api/biart", response_model=schemas.Biart, status_code=201)
def criar_biart(r: schemas.BiartCriar, db: Session = Depends(get_db)):
    novo = models.BiartRegistro(**r.model_dump())
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/biart/{registro_id}", response_model=schemas.Biart)
def atualizar_biart(registro_id: int, dados: schemas.BiartAtualizar, db: Session = Depends(get_db)):
    r = db.query(models.BiartRegistro).filter(models.BiartRegistro.id == registro_id).first()
    if not r:
        raise HTTPException(status_code=404, detail="Registro não encontrado.")
    for campo, valor in dados.model_dump(exclude_unset=True).items():
        setattr(r, campo, valor)
    db.commit()
    db.refresh(r)
    return r


@app.delete("/api/biart/{registro_id}", status_code=204)
def excluir_biart(registro_id: int, db: Session = Depends(get_db)):
    r = db.query(models.BiartRegistro).filter(models.BiartRegistro.id == registro_id).first()
    if not r:
        raise HTTPException(status_code=404, detail="Registro não encontrado.")
    db.delete(r)
    db.commit()
    return None


# Serve as páginas estáticas (uma pasta por ferramenta) na raiz do site.
app.mount("/", StaticFiles(directory="static", html=True), name="static")
