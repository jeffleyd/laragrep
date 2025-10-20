# LaraGrep

Pacote Laravel para transformar perguntas em linguagem natural em consultas Eloquent parametrizadas com auxílio de um modelo da OpenAI ou da Anthropic. O pacote expõe uma rota de API, carrega metadados das tabelas e traduz a resposta do modelo em comandos executáveis com fallback seguro para consultas SQL brutas somente-leitura.

## Instalação

Compatível com projetos Laravel 9.x e 10.x.

```bash
composer require laragrep/laragrep
```

Publique o arquivo de configuração para customizar credenciais, middleware, prefixo da rota e tabelas ignoradas:

```bash
php artisan vendor:publish --tag=laragrep-config
```

Defina sua chave de API (OpenAI ou Anthropic) — é possível usar `LARAGREP_API_KEY`, `OPENAI_API_KEY` ou `ANTHROPIC_API_KEY` — e demais variáveis no `.env`:

```env
LARAGREP_PROVIDER=openai # ou anthropic
LARAGREP_API_KEY=sk-...
LARAGREP_MODEL=gpt-3.5-turbo
LARAGREP_MAX_TOKENS=1024
LARAGREP_ANTHROPIC_VERSION=2023-06-01
LARAGREP_DATABASE_TYPE=MariaDB 10.6
LARAGREP_DATABASE_NAME=retos_live
LARAGREP_EXCLUDE_TABLES=migrations,password_resets
LARAGREP_CONNECTION=mysql
LARAGREP_DEBUG=false
```

A URL base de cada provedor é detectada automaticamente, mas você pode sobrescrever via `LARAGREP_BASE_URL` quando necessário (por exemplo, para apontar para um proxy).

## Metadados do esquema

O LaraGrep lê automaticamente o catálogo do banco configurado para montar o contexto de tabelas e colunas. A conexão utilizada é a mesma definida pelo Laravel ou a informada em `laragrep.contexts.default.connection` (via `LARAGREP_CONNECTION`). O carregamento consulta as visões `information_schema.TABLES` e `information_schema.COLUMNS`, respeitando a lista de exclusões configurada em `laragrep.contexts.default.exclude_tables` (`LARAGREP_EXCLUDE_TABLES`). Comentários/descrições das tabelas e colunas no banco são utilizados como documentação para o modelo. Informe o tipo e o nome do banco utilizado via `laragrep.contexts.default.database` (`LARAGREP_DATABASE_TYPE` e `LARAGREP_DATABASE_NAME`) para que o prompt enviado à IA carregue esse contexto automaticamente.

Além do carregamento automático, é possível complementar ou substituir informações pelo array `contexts.default.tables` no arquivo `config/laragrep.php`. Cada item segue a estrutura abaixo:

```php
return [
    // ...outras opções
    'contexts' => [
        'default' => [
            // ...outras opções do contexto padrão
            'tables' => [
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
                // ...outras tabelas documentadas manualmente
            ],
        ],
    ],
];
```

- `name` é obrigatório e identifica a entidade que será exibida no prompt.
- `description` é opcional e aceita qualquer texto livre.
- `model` é opcional; quando informado, o nome completo da classe é incluído no prompt e usado para resolver passos Eloquent que tragam apenas o apelido (ex.: `users`). Caso a classe não seja fornecida, a execução cai automaticamente para o construtor de consultas (`DB::table('users')`) utilizando o próprio `name` como tabela.
- `columns` é um array opcional; cada coluna pode ter `name`, `type` e `description`.

Além disso, utilize o bloco `contexts.default.database` da configuração para sinalizar explicitamente à IA o tipo do banco (ex.: "MariaDB 10.6") e o nome da base acessada. Esse contexto adicional é enviado ao modelo e reduz a chance de consultas inválidas.

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

### Contextos nomeados

Quando você trabalha com múltiplos bancos ou conjuntos de tabelas, defina contextos nomeados e selecione-os diretamente na URL. Utilize o bloco `contexts` para sobrescrever conexão, nome do banco, tabelas a ignorar ou mesmo a lista de `tables` utilizada no contexto padrão:

```php
return [
    'contexts' => [
        'adf' => [
            'connection' => 'mysql_adf',
            'database' => ['type' => 'MariaDB 10.6', 'name' => 'adf_reporting'],
            'exclude_tables' => ['migrations'],
            'tables' => [...],
        ],
    ],
];
```

Ao enviar um `POST` para `/laragrep/{contexto}`, o pacote aplica as overrides definidas para esse nome (conexão, exclusões e dados do banco). Caso nenhum contexto seja informado, o comportamento padrão permanece inalterado.

## Uso

1. Certifique-se de que suas tabelas e colunas possuem descrições/comentários no banco. O pacote consulta o `information_schema` utilizando a conexão configurada.
2. Ajuste `laragrep.contexts.default.exclude_tables` no arquivo de configuração para esconder tabelas sensíveis de clientes.
3. Envie uma requisição `POST` para a rota publicada (`/laragrep` por padrão) com o payload abaixo. Opcionalmente, inclua o contexto desejado na URL (ex.: `/laragrep/adf`) para aplicar as configurações específicas daquele banco.

```json
{
  "question": "Quais clientes novos criaram pedidos esta semana?"
}
```

A resposta incluirá os passos (Eloquent ou SQL) gerados e os resultados materializados.

Para depuração, defina `debug` como `true` no payload ou habilite `LARAGREP_DEBUG` para receber, junto da resposta, o log das consultas executadas.

Para proteger a rota utilize middleware no array `laragrep.route.middleware` no arquivo de configuração.

