"""
schemas.py — formato dos dados que entram e saem da API.
"""
from datetime import date, time, datetime
from typing import Optional
from pydantic import BaseModel, ConfigDict


# ── Agenda ──────────────────────────────────────────────────────
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


# ── Chamados ────────────────────────────────────────────────────
class ChamadoBase(BaseModel):
    titulo: str
    sistema: Optional[str] = None
    tipo: Optional[str] = None
    descricao: str
    aberto_por: str
    envolvidos: Optional[str] = None
    prazo: Optional[date] = None
    status: str = "Aberto"


class ChamadoCriar(ChamadoBase):
    pass


class ChamadoAtualizar(BaseModel):
    titulo: Optional[str] = None
    sistema: Optional[str] = None
    tipo: Optional[str] = None
    descricao: Optional[str] = None
    aberto_por: Optional[str] = None
    envolvidos: Optional[str] = None
    prazo: Optional[date] = None
    status: Optional[str] = None


class Chamado(ChamadoBase):
    model_config = ConfigDict(from_attributes=True)
    id: int
    criado_em: Optional[datetime] = None
    atualizado_em: Optional[datetime] = None


class NovidadeCriar(BaseModel):
    data: date
    sistema: Optional[str] = None
    texto: str


class Novidade(NovidadeCriar):
    model_config = ConfigDict(from_attributes=True)
    id: int


# ── Plano de Ação ───────────────────────────────────────────────
class PlanoItemBase(BaseModel):
    num: Optional[int] = None
    atividade: str
    quem: Optional[str] = None
    oque: Optional[str] = None
    como: Optional[str] = None
    custo: float = 0
    eficacia: Optional[str] = None
    pct: int = 0


class PlanoItemCriar(PlanoItemBase):
    pass


class PlanoItemAtualizar(BaseModel):
    num: Optional[int] = None
    atividade: Optional[str] = None
    quem: Optional[str] = None
    oque: Optional[str] = None
    como: Optional[str] = None
    custo: Optional[float] = None
    eficacia: Optional[str] = None
    pct: Optional[int] = None


class PlanoItem(PlanoItemBase):
    model_config = ConfigDict(from_attributes=True)
    id: int


# ── Biarticulado ────────────────────────────────────────────────
class BiartBase(BaseModel):
    cpd: Optional[str] = None
    nome: str
    sexo: Optional[str] = None
    data: Optional[date] = None
    autorizacao: Optional[str] = None
    processo: Optional[str] = None


class BiartCriar(BiartBase):
    pass


class BiartAtualizar(BaseModel):
    cpd: Optional[str] = None
    nome: Optional[str] = None
    sexo: Optional[str] = None
    data: Optional[date] = None
    autorizacao: Optional[str] = None
    processo: Optional[str] = None


class Biart(BiartBase):
    model_config = ConfigDict(from_attributes=True)
    id: int


# ── Reuniões ────────────────────────────────────────────────────
class ReuniaoBase(BaseModel):
    titulo: str
    data: date
    inicio: str
    fim: Optional[str] = None
    local: Optional[str] = None
    participantes: Optional[str] = None
    observacoes: Optional[str] = None
    pasta: Optional[str] = None


class ReuniaoCriar(ReuniaoBase):
    criado_por: Optional[str] = None


class ReuniaoAtualizar(BaseModel):
    titulo: Optional[str] = None
    data: Optional[date] = None
    inicio: Optional[str] = None
    fim: Optional[str] = None
    local: Optional[str] = None
    participantes: Optional[str] = None
    observacoes: Optional[str] = None
    pasta: Optional[str] = None


class Reuniao(ReuniaoBase):
    model_config = ConfigDict(from_attributes=True)
    id: int
    criado_por: Optional[str] = None
    criado_em: Optional[datetime] = None


class ReuniaoAnexoInfo(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    reuniao_id: int
    nome: str
    tipo: str
    tamanho: int
    criado_em: Optional[datetime] = None


# ── Salas ───────────────────────────────────────────────────────
class SalaAgendamentoBase(BaseModel):
    sala_id: str
    data: date
    inicio: str
    fim: str
    responsavel: str
    evento: str
    observacoes: Optional[str] = None


class SalaAgendamentoCriar(SalaAgendamentoBase):
    criado_por: Optional[str] = None


class SalaAgendamentoAtualizar(BaseModel):
    sala_id: Optional[str] = None
    data: Optional[date] = None
    inicio: Optional[str] = None
    fim: Optional[str] = None
    responsavel: Optional[str] = None
    evento: Optional[str] = None
    observacoes: Optional[str] = None


class SalaAgendamento(SalaAgendamentoBase):
    model_config = ConfigDict(from_attributes=True)
    id: int
    criado_por: Optional[str] = None
    criado_em: Optional[datetime] = None


# ── Login / usuários ────────────────────────────────────────────
class LoginEntrada(BaseModel):
    usuario: str
    senha: str


class LoginSaida(BaseModel):
    token: str
    nome: str
    usuario: str
    role: str


class UsuarioBase(BaseModel):
    nome: str
    usuario: str
    role: str = "usuario"


class UsuarioCriar(UsuarioBase):
    senha: str


class UsuarioAtualizar(BaseModel):
    nome: Optional[str] = None
    role: Optional[str] = None
    ativo: Optional[bool] = None
    senha: Optional[str] = None


class Usuario(UsuarioBase):
    model_config = ConfigDict(from_attributes=True)
    id: int
    ativo: bool
    criado_em: Optional[datetime] = None


# ── Acidentes (só leitura — histórico importado) ────────────────
class Acidente(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    data: Optional[date] = None
    colaborador: Optional[str] = None
    equipamento: Optional[str] = None
    linha: Optional[str] = None
    atendente: Optional[str] = None
    avaliacao: Optional[str] = None
    evitado: Optional[str] = None
    clima: Optional[str] = None
    tipo_dia: Optional[str] = None
    hora: Optional[str] = None
    culpado: bool
    evitavel: bool
    vitima: bool
    perdakm: bool
