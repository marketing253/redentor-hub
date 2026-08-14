# Empacota o piloto da Agenda pra rodar em qualquer lugar que entenda
# Docker — é assim que o EasyPanel (e Railway/Render também, se um dia
# trocar) sabe exatamente como montar e rodar o serviço.
FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
