"""
models.py — formato das tabelas no banco de dados.

Um serviço só, várias ferramentas: Agenda, Chamados, Plano de Ação e
Biarticulado. Cada uma com sua tabela, todas no mesmo banco Postgres.
"""
from sqlalchemy import Column, Integer, String, Date, Time, Text, DateTime, Float, LargeBinary, func
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


class Chamado(Base):
    __tablename__ = "chamados"
    id = Column(Integer, primary_key=True, index=True)
    titulo = Column(String(200), nullable=False)
    sistema = Column(String(100), nullable=True)
    tipo = Column(String(50), nullable=True)
    descricao = Column(Text, nullable=False)
    aberto_por = Column(String(100), nullable=False)
    envolvidos = Column(String(300), nullable=True)
    prazo = Column(Date, nullable=True)
    status = Column(String(30), nullable=False, default="Aberto")
    criado_em = Column(DateTime(timezone=True), server_default=func.now())
    atualizado_em = Column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


class Novidade(Base):
    __tablename__ = "novidades"
    id = Column(Integer, primary_key=True, index=True)
    data = Column(Date, nullable=False)
    sistema = Column(String(100), nullable=True)
    texto = Column(Text, nullable=False)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())


class PlanoItem(Base):
    """Plano de Ação — atividades do projeto de IA interno (5W1H)."""
    __tablename__ = "plano_itens"
    id = Column(Integer, primary_key=True, index=True)
    num = Column(Integer, nullable=True)
    atividade = Column(String(300), nullable=False)
    quem = Column(String(200), nullable=True)
    oque = Column(String(300), nullable=True)
    como = Column(Text, nullable=True)
    custo = Column(Float, nullable=False, default=0)
    eficacia = Column(Text, nullable=True)
    pct = Column(Integer, nullable=False, default=0)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())
    atualizado_em = Column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


class BiartRegistro(Base):
    """Treinamento Biarticulado — autorização/processo por colaborador."""
    __tablename__ = "biart_registros"
    id = Column(Integer, primary_key=True, index=True)
    cpd = Column(String(20), nullable=True, index=True)
    nome = Column(String(150), nullable=False)
    sexo = Column(String(10), nullable=True)
    data = Column(Date, nullable=True)
    autorizacao = Column(String(60), nullable=True)
    processo = Column(String(100), nullable=True)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())
    atualizado_em = Column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())


class Reuniao(Base):
    __tablename__ = "reunioes"
    id = Column(Integer, primary_key=True, index=True)
    titulo = Column(String(200), nullable=False)
    data = Column(Date, nullable=False)
    inicio = Column(String(5), nullable=False)   # "HH:MM", igual ao app original
    fim = Column(String(5), nullable=True)
    local = Column(String(150), nullable=True)
    participantes = Column(String(400), nullable=True)
    observacoes = Column(Text, nullable=True)
    pasta = Column(String(100), nullable=True)
    criado_por = Column(String(80), nullable=True)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())


class ReuniaoAnexo(Base):
    """Anexos de reunião guardados no próprio banco (mesmo princípio do
    anexos.php antigo: BLOB no banco), só que sem depender do Hub PHP."""
    __tablename__ = "reuniao_anexos"
    id = Column(Integer, primary_key=True, index=True)
    reuniao_id = Column(Integer, nullable=False, index=True)
    nome = Column(String(255), nullable=False)
    tipo = Column(String(120), nullable=False)
    tamanho = Column(Integer, nullable=False)
    conteudo = Column(LargeBinary, nullable=False)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())


class SalaAgendamento(Base):
    """As salas em si (João Gulin / Angelo Gulin) ficam fixas no código
    do front, igual já era — só os agendamentos vêm do banco."""
    __tablename__ = "salas_agendamentos"
    id = Column(Integer, primary_key=True, index=True)
    sala_id = Column(String(30), nullable=False, index=True)
    data = Column(Date, nullable=False)
    inicio = Column(String(5), nullable=False)
    fim = Column(String(5), nullable=False)
    responsavel = Column(String(120), nullable=False)
    evento = Column(String(200), nullable=False)
    observacoes = Column(Text, nullable=True)
    criado_por = Column(String(80), nullable=True)
    criado_em = Column(DateTime(timezone=True), server_default=func.now())
