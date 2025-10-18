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
LARAGREP_CONNECTION=mysql
LARAGREP_DEBUG=false
```

## Metadados do esquema

O LaraGrep lê automaticamente o catálogo do banco configurado para montar o contexto de tabelas e colunas. A conexão utilizada é a mesma definida pelo Laravel ou a informada em `laragrep.connection` (via `LARAGREP_CONNECTION`). O carregamento consulta as visões `information_schema.TABLES` e `information_schema.COLUMNS`, respeitando a lista de exclusões configurada em `laragrep.exclude_tables` (`LARAGREP_EXCLUDE_TABLES`). Comentários/descrições das tabelas e colunas no banco são utilizados como documentação para o modelo.

Além do carregamento automático, é possível complementar ou substituir informações pelo array `metadata` no arquivo `config/laragrep.php`. Cada item segue a estrutura abaixo:

```php
return [
    // ...outras opções
    'metadata' => [
        [
            'name' => 'orders', // nome da tabela, view ou conjunto lógico
            'description' => 'Pedidos realizados pelos clientes', // opcional
            'model' => App\\Models\\Order::class, // opcional, usado para executar passos Eloquent
            'columns' => [
                [
                    'name' => 'id',
                    'type' => 'bigint unsigned', // opcional
                    'description' => 'Chave primária', // opcional
                ],
                [
                    'name' => 'user_id',
                    'type' => 'bigint unsigned',
                    'description' => 'Relaciona com users.id',
                ],
            ],
        ],
        // ...outros objetos de metadados
    ],
];
```

- `name` é obrigatório e identifica a entidade que será exibida no prompt.
- `description` é opcional e aceita qualquer texto livre.
- `model` é opcional; quando informado, o nome completo da classe é incluído no prompt e usado para resolver passos Eloquent que tragam apenas o apelido (ex.: `users`). Caso a classe não seja fornecida, a execução cai automaticamente para o construtor de consultas (`DB::table('users')`) utilizando o próprio `name` como tabela.
- `columns` é um array opcional; cada coluna pode ter `name`, `type` e `description`.

Quando o contexto é enviado ao modelo, cada entrada aparece resumida de forma semelhante a:

```
Table users (Model: App\Models\User) — Tabela com todos usuários cadastrados
- id (bigint unsigned): Chave primária
- padrino_code (varchar): Código do vendedor
- point_of_sale_id (bigint unsigned): Ponto de vendas vinculado ao usuário
- profile (varchar): Perfil do usuário, ex: Shop Owner, Shop Assistant, Shop Assistant 1, Shop Assistant 2, Shop Assistant 3, Shop Assistant 4
```

Se nenhuma classe for informada, o trecho ` (Model: ...)` é omitido automaticamente.

As entradas definidas manualmente são mescladas às que vêm do banco. Dessa forma você pode documentar views, tabelas lógicas ou mesmo campos calculados que não existam fisicamente. Também é possível deixar o array vazio para depender apenas do carregamento automático.

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
