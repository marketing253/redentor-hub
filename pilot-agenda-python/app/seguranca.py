"""
seguranca.py — hash de senha e geração de token de sessão.

Sem depender de bibliotecas nativas (bcrypt etc.) pra não complicar o
build no Docker: usa pbkdf2_hmac da própria biblioteca padrão do
Python, com salto aleatório por senha.
"""
import hashlib
import secrets

_ITERACOES = 200_000


def hash_senha(senha: str) -> str:
    sal = secrets.token_hex(16)
    h = hashlib.pbkdf2_hmac("sha256", senha.encode("utf-8"), sal.encode("utf-8"), _ITERACOES)
    return f"{sal}${h.hex()}"


def verificar_senha(senha: str, hash_guardado: str) -> bool:
    try:
        sal, h_hex = hash_guardado.split("$", 1)
    except ValueError:
        return False
    h = hashlib.pbkdf2_hmac("sha256", senha.encode("utf-8"), sal.encode("utf-8"), _ITERACOES)
    return secrets.compare_digest(h.hex(), h_hex)


def gerar_token() -> str:
    return secrets.token_hex(32)
