"""
main.py — a API da Agenda pessoal.

Piloto da migração pra Python: prova que dá pra ter um serviço novo,
com banco de dados de verdade (não localStorage), publicado sozinho
a cada git push, sem precisar mexer em Gerenciador de Arquivos.

O QUE ESTÁ SIMPLIFICADO DE PROPÓSITO (fase piloto, não produção ainda)
  O login aqui é só um campo de usuário, sem senha — cada pessoa só
  enxerga os próprios eventos, mas não há verificação de identidade
  ainda. Antes de isso virar o substituto de verdade do agenda.html
  no Hub, o próximo passo é ligar esse "usuario" à sessão real do
  Redentor Hub (mesma tabela portal_usuarios/portal_tokens que o
  PHP já usa) — fica pra quando este piloto estiver validado.
"""
from typing import List

from fastapi import FastAPI, Depends, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse
from sqlalchemy.orm import Session
from sqlalchemy import asc

from app.database import engine, get_db, Base
from app import models, schemas

# Cria a tabela sozinha no primeiro start, se ainda não existir —
# mesma filosofia que o CREATE TABLE IF NOT EXISTS que o PHP já usava.
Base.metadata.create_all(bind=engine)

app = FastAPI(title="Redentor — Agenda (piloto Python)")

# Libera chamadas vindas de qualquer origem por enquanto (é um piloto
# testado solto, fora do Hub). Quando entrar no Hub de verdade, isso
# se restringe ao domínio real do portal.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/api/eventos", response_model=List[schemas.Evento])
def listar_eventos(usuario: str, db: Session = Depends(get_db)):
    """Todos os eventos de um usuário, do mais antigo pro mais novo."""
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


@app.get("/api/saude")
def saude():
    """Endpoint simples pra confirmar que o serviço está de pé."""
    return {"ok": True}


# Serve a página (static/index.html) e seus arquivos na raiz do site.
app.mount("/", StaticFiles(directory="static", html=True), name="static")
