# LaraGrep

Pacote Laravel para transformar perguntas em linguagem natural em consultas Eloquent parametrizadas com auxílio de um modelo da OpenAI. O pacote expõe uma rota de API, carrega metadados das tabelas e traduz a resposta do modelo em comandos executáveis com fallback seguro para consultas SQL brutas somente-leitura.

## Instalação

Compatível com projetos Laravel 9.x e 10.x.

```bash
composer require laragrep/laragrep
```

Publique o arquivo de configuração para customizar credenciais, middleware, prefixo da rota e tabelas ignoradas:

```bash
php artisan vendor:publish --tag=laragrep-config
```

Defina sua chave de API da OpenAI (ou sobrescreva via variáveis específicas da LaraGrep) e demais variáveis no `.env`:

```env
LARAGREP_API_KEY=sk-...
LARAGREP_BASE_URL=https://api.openai.com/v1/chat/completions
LARAGREP_MODEL=gpt-3.5-turbo
LARAGREP_EXCLUDE_TABLES=migrations,password_resets
LARAGREP_DEBUG=false
```

## Uso

1. Certifique-se de que suas tabelas e colunas possuem descrições/comentários no banco. O pacote consulta o `information_schema` utilizando a conexão configurada.
2. Ajuste `laragrep.exclude_tables` no arquivo de configuração para esconder tabelas sensíveis de clientes.
3. Envie uma requisição `POST` para a rota publicada (`/laragrep` por padrão) com o payload:

```json
{
  "question": "Quais clientes novos criaram pedidos esta semana?"
}
```

A resposta incluirá os passos (Eloquent ou SQL) gerados e os resultados materializados.

Para depuração, defina `debug` como `true` no payload ou habilite `LARAGREP_DEBUG` para receber, junto da resposta, o log das consultas executadas.

Para proteger a rota utilize middleware no array `laragrep.route.middleware` no arquivo de configuração.

## Testes

```bash
composer test
```

Os testes cobrem o parser de metadados (incluindo exclusões), a construção do prompt e a execução de consultas mockadas.
