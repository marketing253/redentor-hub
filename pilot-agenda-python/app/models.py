"""
models.py — formato da tabela de eventos no banco de dados.

Um compromisso pertence a um usuário (campo "usuario", o mesmo login
usado no Hub) — assim cada pessoa só vê a própria agenda.
"""
from sqlalchemy import Column, Integer, String, Date, Time, Text, DateTime, func
from app.database import Base


class Evento(Base):
    __tablename__ = "eventos"

    id = Column(Integer, primary_key=True, index=True)
    usuario = Column(String(60), nullable=False, index=True)
    titulo = Column(String(200), nullable=False)
    data = Column(Date, nullable=False)
    hora = Column(Time, nullable=True)
    descricao = Column(Text, nullable=True)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())
