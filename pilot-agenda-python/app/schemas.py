"""
schemas.py — formato dos dados que entram e saem da API (validação
automática do FastAPI: se faltar campo ou vier tipo errado, ele
recusa a requisição com uma mensagem clara, sem precisar escrever
essa checagem na mão).
"""
from datetime import date, time, datetime
from typing import Optional
from pydantic import BaseModel, ConfigDict


class EventoBase(BaseModel):
    titulo: str
    data: date
    hora: Optional[time] = None
    descricao: Optional[str] = None


class EventoCriar(EventoBase):
    usuario: str


class EventoAtualizar(BaseModel):
    titulo: Optional[str] = None
    data: Optional[date] = None
    hora: Optional[time] = None
    descricao: Optional[str] = None


class Evento(EventoBase):
    model_config = ConfigDict(from_attributes=True)
    id: int
    usuario: str
    criado_em: Optional[datetime] = None
