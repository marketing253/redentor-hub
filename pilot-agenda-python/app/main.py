"""
main.py — API das ferramentas em Python: Agenda, Chamados, Plano de
Ação e Biarticulado. Um serviço só, um banco só, publicado sozinho a
cada git push.
"""
import base64
from datetime import datetime, timedelta
from typing import List

from fastapi import FastAPI, Depends, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.responses import Response, JSONResponse
from pydantic import BaseModel
from sqlalchemy.orm import Session
from sqlalchemy import asc, desc, func

from app.database import engine, get_db, SessionLocal, Base
from app import models, schemas
from app.seed import rodar_seed
from app.seguranca import hash_senha, verificar_senha, gerar_token

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

# ══════════════════════════════════════ Login ═══════════════════
DURACAO_SESSAO = timedelta(days=7)
_CAMINHOS_PUBLICOS = {"/api/login", "/api/saude"}


def _extrair_token(cabecalho):
    if not cabecalho or not cabecalho.lower().startswith("bearer "):
        return None
    return cabecalho[7:].strip()


def _rota_publica(metodo, caminho):
    if caminho in _CAMINHOS_PUBLICOS:
        return True
    # o painel físico da porta (painel_sala.php, no Hub antigo) lê os
    # agendamentos sem login — é só leitura, exibida na tela da sala.
    if metodo == "GET" and caminho == "/api/salas/agendamentos":
        return True
    return False


@app.middleware("http")
async def exigir_autenticacao(request: Request, call_next):
    """Toda rota /api/* exige sessão válida, com as exceções de
    _rota_publica. Verifica aqui (em vez de Depends em cada rota) pra
    não ter que tocar em cada endpoint que já existia antes do login
    existir."""
    caminho = request.url.path
    if request.method != "OPTIONS" and caminho.startswith("/api/") and not _rota_publica(request.method, caminho):
        token = _extrair_token(request.headers.get("authorization"))
        usuario = None
        if token:
            with SessionLocal() as db:
                sessao = db.query(models.SessaoToken).filter(models.SessaoToken.token == token).first()
                if sessao and sessao.expira_em >= datetime.utcnow():
                    usuario = (
                        db.query(models.Usuario)
                        .filter(models.Usuario.id == sessao.usuario_id, models.Usuario.ativo.is_(True))
                        .first()
                    )
        if not usuario:
            return JSONResponse(status_code=401, content={"detail": "Não autenticado."})
        request.state.usuario_id = usuario.id
        request.state.usuario_role = usuario.role
        request.state.usuario_nome = usuario.usuario
    return await call_next(request)


def exigir_admin(request: Request):
    if getattr(request.state, "usuario_role", None) != "admin":
        raise HTTPException(status_code=403, detail="Apenas administradores.")


@app.get("/api/saude")
def saude():
    return {"ok": True}


@app.post("/api/login", response_model=schemas.LoginSaida)
def login(dados: schemas.LoginEntrada, db: Session = Depends(get_db)):
    usuario_login = dados.usuario.strip().lower()
    u = db.query(models.Usuario).filter(models.Usuario.usuario == usuario_login, models.Usuario.ativo.is_(True)).first()
    if not u or not verificar_senha(dados.senha, u.senha_hash):
        raise HTTPException(status_code=401, detail="Usuário ou senha inválidos.")
    token = gerar_token()
    db.add(models.SessaoToken(
        token=token, usuario_id=u.id,
        criado_em=datetime.utcnow(), expira_em=datetime.utcnow() + DURACAO_SESSAO,
    ))
    db.commit()
    return schemas.LoginSaida(token=token, nome=u.nome, usuario=u.usuario, role=u.role)


@app.post("/api/logout", status_code=204)
def logout(request: Request, db: Session = Depends(get_db)):
    token = _extrair_token(request.headers.get("authorization"))
    if token:
        db.query(models.SessaoToken).filter(models.SessaoToken.token == token).delete()
        db.commit()
    return None


@app.get("/api/me")
def me(request: Request, db: Session = Depends(get_db)):
    u = db.query(models.Usuario).filter(models.Usuario.id == request.state.usuario_id).first()
    if not u:
        raise HTTPException(status_code=401, detail="Não autenticado.")
    return {"nome": u.nome, "usuario": u.usuario, "role": u.role}


# ══════════════════════════════════════ Usuários (admin) ════════
@app.get("/api/usuarios", response_model=List[schemas.Usuario], dependencies=[Depends(exigir_admin)])
def listar_usuarios(db: Session = Depends(get_db)):
    return db.query(models.Usuario).order_by(asc(models.Usuario.nome)).all()


@app.post("/api/usuarios", response_model=schemas.Usuario, status_code=201, dependencies=[Depends(exigir_admin)])
def criar_usuario(u: schemas.UsuarioCriar, db: Session = Depends(get_db)):
    login_norm = u.usuario.strip().lower()
    if db.query(models.Usuario).filter(models.Usuario.usuario == login_norm).first():
        raise HTTPException(status_code=400, detail="Já existe um usuário com esse login.")
    if not u.senha or len(u.senha) < 6:
        raise HTTPException(status_code=400, detail="A senha precisa ter ao menos 6 caracteres.")
    novo = models.Usuario(
        nome=u.nome, usuario=login_norm, senha_hash=hash_senha(u.senha),
        role=u.role if u.role in ("admin", "usuario") else "usuario", ativo=True,
    )
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/usuarios/{usuario_id}", response_model=schemas.Usuario, dependencies=[Depends(exigir_admin)])
def atualizar_usuario(usuario_id: int, dados: schemas.UsuarioAtualizar, db: Session = Depends(get_db)):
    u = db.query(models.Usuario).filter(models.Usuario.id == usuario_id).first()
    if not u:
        raise HTTPException(status_code=404, detail="Usuário não encontrado.")
    campos = dados.model_dump(exclude_unset=True)
    senha = campos.pop("senha", None)
    if senha:
        if len(senha) < 6:
            raise HTTPException(status_code=400, detail="A senha precisa ter ao menos 6 caracteres.")
        u.senha_hash = hash_senha(senha)
    if "role" in campos and campos["role"] not in ("admin", "usuario"):
        raise HTTPException(status_code=400, detail="Papel inválido.")
    for campo, valor in campos.items():
        setattr(u, campo, valor)
    db.commit()
    db.refresh(u)
    return u


@app.delete("/api/usuarios/{usuario_id}", status_code=204, dependencies=[Depends(exigir_admin)])
def excluir_usuario(usuario_id: int, request: Request, db: Session = Depends(get_db)):
    if usuario_id == request.state.usuario_id:
        raise HTTPException(status_code=400, detail="Você não pode excluir seu próprio usuário.")
    u = db.query(models.Usuario).filter(models.Usuario.id == usuario_id).first()
    if not u:
        raise HTTPException(status_code=404, detail="Usuário não encontrado.")
    db.query(models.SessaoToken).filter(models.SessaoToken.usuario_id == usuario_id).delete()
    db.delete(u)
    db.commit()
    return None


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


# ══════════════════════════════════════ Reuniões ════════════════
@app.get("/api/reunioes", response_model=List[schemas.Reuniao])
def listar_reunioes(db: Session = Depends(get_db)):
    return db.query(models.Reuniao).order_by(desc(models.Reuniao.data)).all()


@app.post("/api/reunioes", response_model=schemas.Reuniao, status_code=201)
def criar_reuniao(r: schemas.ReuniaoCriar, db: Session = Depends(get_db)):
    novo = models.Reuniao(**r.model_dump())
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/reunioes/{reuniao_id}", response_model=schemas.Reuniao)
def atualizar_reuniao(reuniao_id: int, dados: schemas.ReuniaoAtualizar, db: Session = Depends(get_db)):
    r = db.query(models.Reuniao).filter(models.Reuniao.id == reuniao_id).first()
    if not r:
        raise HTTPException(status_code=404, detail="Reunião não encontrada.")
    for campo, valor in dados.model_dump(exclude_unset=True).items():
        setattr(r, campo, valor)
    db.commit()
    db.refresh(r)
    return r


@app.delete("/api/reunioes/{reuniao_id}", status_code=204)
def excluir_reuniao(reuniao_id: int, db: Session = Depends(get_db)):
    r = db.query(models.Reuniao).filter(models.Reuniao.id == reuniao_id).first()
    if not r:
        raise HTTPException(status_code=404, detail="Reunião não encontrada.")
    db.query(models.ReuniaoAnexo).filter(models.ReuniaoAnexo.reuniao_id == reuniao_id).delete()
    db.delete(r)
    db.commit()
    return None


class AnexoEnviar(BaseModel):
    nome: str
    tipo: str
    dados: str  # base64


@app.get("/api/reunioes/{reuniao_id}/anexos", response_model=List[schemas.ReuniaoAnexoInfo])
def listar_anexos(reuniao_id: int, db: Session = Depends(get_db)):
    return (
        db.query(models.ReuniaoAnexo)
        .filter(models.ReuniaoAnexo.reuniao_id == reuniao_id)
        .order_by(desc(models.ReuniaoAnexo.criado_em))
        .all()
    )


@app.post("/api/reunioes/{reuniao_id}/anexos", response_model=schemas.ReuniaoAnexoInfo, status_code=201)
def enviar_anexo(reuniao_id: int, anexo: AnexoEnviar, db: Session = Depends(get_db)):
    try:
        conteudo = base64.b64decode(anexo.dados, validate=True)
    except Exception:
        raise HTTPException(status_code=400, detail="Conteúdo em base64 inválido.")
    if len(conteudo) > 16 * 1024 * 1024:
        raise HTTPException(status_code=400, detail="Arquivo excede 16 MB.")
    novo = models.ReuniaoAnexo(
        reuniao_id=reuniao_id, nome=anexo.nome, tipo=anexo.tipo,
        tamanho=len(conteudo), conteudo=conteudo,
    )
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.get("/api/anexos/{anexo_id}")
def baixar_anexo(anexo_id: int, db: Session = Depends(get_db)):
    a = db.query(models.ReuniaoAnexo).filter(models.ReuniaoAnexo.id == anexo_id).first()
    if not a:
        raise HTTPException(status_code=404, detail="Anexo não encontrado.")
    return Response(content=a.conteudo, media_type=a.tipo,
                     headers={"Content-Disposition": f'inline; filename="{a.nome}"'})


@app.delete("/api/anexos/{anexo_id}", status_code=204)
def excluir_anexo(anexo_id: int, db: Session = Depends(get_db)):
    a = db.query(models.ReuniaoAnexo).filter(models.ReuniaoAnexo.id == anexo_id).first()
    if not a:
        raise HTTPException(status_code=404, detail="Anexo não encontrado.")
    db.delete(a)
    db.commit()
    return None


# ══════════════════════════════════════ Salas ═══════════════════
@app.get("/api/salas/agendamentos", response_model=List[schemas.SalaAgendamento])
def listar_agendamentos(db: Session = Depends(get_db)):
    return db.query(models.SalaAgendamento).order_by(asc(models.SalaAgendamento.data), asc(models.SalaAgendamento.inicio)).all()


@app.post("/api/salas/agendamentos", response_model=schemas.SalaAgendamento, status_code=201)
def criar_agendamento(a: schemas.SalaAgendamentoCriar, db: Session = Depends(get_db)):
    novo = models.SalaAgendamento(**a.model_dump())
    db.add(novo)
    db.commit()
    db.refresh(novo)
    return novo


@app.put("/api/salas/agendamentos/{agendamento_id}", response_model=schemas.SalaAgendamento)
def atualizar_agendamento(agendamento_id: int, dados: schemas.SalaAgendamentoAtualizar, db: Session = Depends(get_db)):
    a = db.query(models.SalaAgendamento).filter(models.SalaAgendamento.id == agendamento_id).first()
    if not a:
        raise HTTPException(status_code=404, detail="Agendamento não encontrado.")
    for campo, valor in dados.model_dump(exclude_unset=True).items():
        setattr(a, campo, valor)
    db.commit()
    db.refresh(a)
    return a


@app.delete("/api/salas/agendamentos/{agendamento_id}", status_code=204)
def excluir_agendamento(agendamento_id: int, db: Session = Depends(get_db)):
    a = db.query(models.SalaAgendamento).filter(models.SalaAgendamento.id == agendamento_id).first()
    if not a:
        raise HTTPException(status_code=404, detail="Agendamento não encontrado.")
    db.delete(a)
    db.commit()
    return None


# ══════════════════════════════════════ Acidentes (só leitura) ══
from typing import Optional as _Optional


@app.get("/api/acidentes/resumo")
def resumo_acidentes(db: Session = Depends(get_db)):
    total = db.query(models.Acidente).count()
    culpados = db.query(models.Acidente).filter(models.Acidente.culpado.is_(True)).count()
    evitaveis = db.query(models.Acidente).filter(models.Acidente.evitavel.is_(True)).count()
    vitimas = db.query(models.Acidente).filter(models.Acidente.vitima.is_(True)).count()
    n_motoristas = db.query(models.Acidente.colaborador).distinct().count()
    n_veiculos = db.query(models.Acidente.equipamento).distinct().count()
    n_linhas = db.query(models.Acidente.linha).distinct().count()
    return {
        "total": total, "culpados": culpados, "evitaveis": evitaveis,
        "vitimas": vitimas, "n_motoristas": n_motoristas,
        "n_veiculos": n_veiculos, "n_linhas": n_linhas,
    }


@app.get("/api/acidentes", response_model=List[schemas.Acidente])
def listar_acidentes(
    db: Session = Depends(get_db),
    linha: _Optional[str] = None,
    colaborador: _Optional[str] = None,
    ano: _Optional[int] = None,
    limit: int = 200,
    offset: int = 0,
):
    q = db.query(models.Acidente)
    if linha:
        q = q.filter(models.Acidente.linha == linha)
    if colaborador:
        q = q.filter(models.Acidente.colaborador.ilike(f"%{colaborador}%"))
    if ano:
        q = q.filter(func.extract("year", models.Acidente.data) == ano)
    return (
        q.order_by(desc(models.Acidente.data))
        .offset(offset).limit(min(limit, 1000))
        .all()
    )


@app.get("/api/acidentes/linhas")
def listar_linhas_acidentes(db: Session = Depends(get_db)):
    """Ranking de linhas por quantidade de acidentes, pro gráfico de barras."""
    rows = (
        db.query(models.Acidente.linha, func.count(models.Acidente.id).label("qtd"))
        .filter(models.Acidente.linha.isnot(None))
        .group_by(models.Acidente.linha)
        .order_by(desc("qtd"))
        .limit(15)
        .all()
    )
    return [{"linha": r[0], "qtd": r[1]} for r in rows]


class AcidenteImportarItem(BaseModel):
    data: _Optional[str] = None
    colaborador: _Optional[str] = None
    equipamento: _Optional[str] = None
    linha: _Optional[str] = None
    atendente: _Optional[str] = None
    avaliacao: _Optional[str] = None
    evitado: _Optional[str] = None
    clima: _Optional[str] = None
    tipo_dia: _Optional[str] = None
    hora: _Optional[str] = None
    culpado: bool = False
    evitavel: bool = False
    vitima: bool = False
    perdakm: bool = False


class AcidenteImportarLote(BaseModel):
    registros: List[AcidenteImportarItem]


@app.post("/api/acidentes/importar")
def importar_acidentes(lote: AcidenteImportarLote, db: Session = Depends(get_db)):
    """Recebe as linhas já lidas da planilha (Excel/CSV) pelo navegador e
    grava — o parse do arquivo em si acontece no front, com a SheetJS,
    igual o painel original fazia. Aqui só valida e insere."""
    from datetime import datetime as _dt

    def _data(s):
        if not s:
            return None
        for fmt in ("%Y-%m-%d", "%d/%m/%Y"):
            try:
                return _dt.strptime(s, fmt).date()
            except ValueError:
                pass
        return None

    inseridos = 0
    for item in lote.registros:
        db.add(models.Acidente(
            data=_data(item.data),
            colaborador=item.colaborador or None,
            equipamento=item.equipamento or None,
            linha=item.linha or None,
            atendente=item.atendente or None,
            avaliacao=item.avaliacao or None,
            evitado=item.evitado or None,
            clima=item.clima or None,
            tipo_dia=item.tipo_dia or None,
            hora=item.hora or None,
            culpado=item.culpado, evitavel=item.evitavel,
            vitima=item.vitima, perdakm=item.perdakm,
        ))
        inseridos += 1
    db.commit()
    return {"ok": True, "inseridos": inseridos}


# ══════════════════════════════════════ Aderência ═══════════════
# O painel original faz todo filtro/gráfico/análise no navegador, em
# cima de um dataset compacto (grupos/linhas/tabelas como listas +
# registros como tuplas de índice). Aqui reconstruímos esse mesmo
# formato a partir da tabela relacional, pra reaproveitar o JS
# original quase sem alterações — só troca de onde os dados vêm.
@app.get("/api/aderencia/dataset")
def dataset_aderencia(db: Session = Depends(get_db)):
    registros = db.query(models.AderenciaRegistro).order_by(asc(models.AderenciaRegistro.id)).all()
    groups, group_idx = [], {}
    lines, line_idx = [], {}
    idents, ident_idx = [], {}
    line_group = []
    rows = []
    for r in registros:
        if r.grupo not in group_idx:
            group_idx[r.grupo] = len(groups)
            groups.append(r.grupo)
        if r.linha not in line_idx:
            line_idx[r.linha] = len(lines)
            lines.append(r.linha)
            line_group.append(group_idx[r.grupo])
        if r.identificacao not in ident_idx:
            ident_idx[r.identificacao] = len(idents)
            idents.append(r.identificacao)
        rows.append([
            ident_idx[r.identificacao], line_idx[r.linha],
            r.total, r.igual, r.dia, r.ano, r.mes,
        ])
    return {
        "groups": groups, "lines": lines, "lineGroup": line_group,
        "idents": idents, "days": ["Útil", "Sábado", "Domingo"], "rows": rows,
    }


@app.post("/api/aderencia/importar")
def importar_aderencia(lote: schemas.AderenciaImportarLote, db: Session = Depends(get_db)):
    """Recebe os registros já validados pelo motor de parsing do próprio
    painel (front) e grava — substitui quando a chave (tabela + linha +
    dia + ano + mês) já existir, igual o app original fazia."""
    novos, substituidos = 0, 0
    for item in lote.registros:
        existente = (
            db.query(models.AderenciaRegistro)
            .filter(
                models.AderenciaRegistro.identificacao == item.id,
                models.AderenciaRegistro.linha == item.ln,
                models.AderenciaRegistro.dia == item.di,
                models.AderenciaRegistro.ano == item.an,
                models.AderenciaRegistro.mes == item.me,
            )
            .first()
        )
        if existente:
            existente.total = item.t
            existente.igual = item.i
            existente.grupo = item.gr
            substituidos += 1
        else:
            db.add(models.AderenciaRegistro(
                identificacao=item.id, linha=item.ln, grupo=item.gr,
                dia=item.di, ano=item.an, mes=item.me, total=item.t, igual=item.i,
            ))
            novos += 1
    db.commit()
    return {"ok": True, "novos": novos, "substituidos": substituidos}


# Serve as páginas estáticas (uma pasta por ferramenta) na raiz do site.
app.mount("/", StaticFiles(directory="static", html=True), name="static")
