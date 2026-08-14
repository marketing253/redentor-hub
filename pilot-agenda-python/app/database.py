"""
database.py — conexão com o banco de dados.

Lê a variável de ambiente DATABASE_URL (é isso que o Railway/Render
já preenchem sozinhos quando você adiciona um banco Postgres ao
projeto). Se não existir (rodando no seu computador sem configurar
nada), usa um arquivo SQLite local — assim dá pra testar sem precisar
instalar Postgres na sua máquina.
"""
import os
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, declarative_base

DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///./agenda.db")

# Railway/Render às vezes fornecem a URL como "postgres://", mas o
# SQLAlchemy moderno exige "postgresql://" — mesma coisa, nome diferente.
if DATABASE_URL.startswith("postgres://"):
    DATABASE_URL = DATABASE_URL.replace("postgres://", "postgresql://", 1)

# connect_args só é necessário pro SQLite (permite usar entre threads,
# que é como o FastAPI/Uvicorn processa as requisições).
connect_args = {"check_same_thread": False} if DATABASE_URL.startswith("sqlite") else {}

engine = create_engine(DATABASE_URL, connect_args=connect_args)
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
Base = declarative_base()


def get_db():
    """Abre uma sessão de banco por requisição e fecha sozinha no final."""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
