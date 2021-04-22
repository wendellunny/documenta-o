<?php

// Controlador de Estrutura

class EstruturaController extends Controller
{
    use SessionTrait, FileTrait, EstruturaTrait;

    /**
     * Show the form for creating a new resource.
     * @return \Illuminate\Http\Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */

    // Exibe a view com o forumlario de cadastro
    public function create()
    {
        $this->authorize('create', Estrutura::class);
        return view('content.company.structure.structure');
    }




    /**
     * Store a newly created resource in storage.
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */


    /*

        INSERE TODOS OS DADOS DE ESTRUTURA NO BANCO 
        
        Primeiro ele valida a requisição, se o campos do formulario sejam passados como desejado

        Depois ele passa por uma politica de autorização, para assim começar todo o processo de gravar no banco de dados

        Após ele armazena outros dados que serão inseridos no banco, como o id do usuario que está logado nessa sessão e está cadastrando tal estrutura, também um caminho amigavel. 

        Após isso ele faz a tentativa de inserir os dados na tabela Estrutura
        
        Porém caso algum erro na inserção no banco ocorra, ele da um rollback voltando os dados que não foram enviados corretamente para o banco.

        Mas se tudo for realizado com sucesso ele commita no banco, ou seja, insere todos os dados da estrutura na tabela Estrutura.
    */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $dataform = $this->validator($request);

        $this->authorize('create', Estrutura::class);
        DB::beginTransaction();

        try {
            $dataform['cliente_id'] = session()->get('current_client')->id;

            
            if ($request->filled('numero_registro')) {
                $dataform = $this->atribuiFicha($dataform);
            }

            if ($request->pai_id === null) {
                $dataform['caminho'] = Str::slug($dataform['title']);
                

            } else {
                $dataform['caminho'] = $request->caminho . '/' . Str::slug($dataform['title']);
            }

            
            Estrutura::query()->create($dataform);
            notify()->success('Estrutura Criada');
            DB::commit();
            return redirect(route('estrutura.create'));

        } catch (\Exception $e) {
            notify()->error('Ocorreu alguma falha, tente novamente');
            DB::rollBack();
            return back()->withErrors($e->getMessage());
        }
    



    /**
     * Clonar a estrutura selecionada e seus dependentes


        *Clona uma estrutura, criando uma nova estrutura identica
     * @param $dataform
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    protected function clonar($dataform)
    {
        DB::beginTransaction();

        try {
            $estrutura_original = Estrutura::query()
                ->with('estrutura_pai')
                ->findOrFail($dataform['clonar']);

            //  Se a estrutura_original não for uma estrutura raiz
            if ($estrutura_original->estrutura_pai !== null) {
                $dataform['pai'] = $estrutura_original->estrutura_pai->id;
                $dataform['caminho'] = $estrutura_original->estrutura_pai->caminho . '/' . Str::slug($dataform['title']);

               
            } else {
                $dataform['pai'] = null;
                $dataform['caminho'] = Str::slug($dataform['title']);
            }

            // estrutura criada
            $estrutura = Estrutura::query()->create($dataform);

           
            $this->navigate($estrutura_original->children, $estrutura);
            return $dataform;

        } catch (\Exception $e) {
            notify()->error(__('alerts.fail_informacao'));
            DB::rollBack();
            return redirect()->back()->withErrors($e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     * @param int $id
     * @return \Illuminate\Http\Response
     * @throws \Illuminate\Auth\Access\AuthorizationException|\Illuminate\View\View
     */
    public function show(int $id) //: \Illuminate\Http\Response|\Illuminate\View\View
    {
        $estrutura = Estrutura::query()
            ->where('hidden', false)
            ->with('categorias')
            ->withCount('categorias')
            ->findOrFail($id);
        $this->authorize('view', $estrutura);
        if (request()->ajax()) {
            return response($estrutura);
        }
        session(['current_structure' => $estrutura]);
        return view('content.company.document.documents');
    }


    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function createSession($id = null): \Illuminate\Http\Response
    {
        return response($this->current_structre($id));
    }


    /**
     * Atualiza Dados de uma estrutura especifica
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response
    {
        $estrutura = Estrutura::query()->find($id);
        $data['title'] = $request->text;
        $data['slug'] = Str::slug($request->text);

        if ($estrutura->update($data)) {
            return response('success', 200);
        }

        return response('fail', 403);
    }

    /**
     * Deleta uma estrutura da base de dados, caso não for efetuado com sucesso retorna um erro 403, que indica um erro ao deletar tal estrutura
     * @param int $id
     * @return \Illuminate\Http\Response
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(int $id): \Illuminate\Http\Response
    {
        $estrutura = Estrutura::query()->findOrFail($id);
        $this->authorize('delete', $estrutura);

        if ($estrutura->delete()) {
            return response('', 204);
        }
        return response('error ao deleter', 403);
    }

    /**
     * Get all root structures for a client, and verify if user has permission for access all structures or selected ones
     * @param $order
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEstrutura($order): \Illuminate\Http\JsonResponse
    {
        $query = Estrutura::query()
            ->withCount('children')
            ->where('estruturas.cliente_id', session()->get('current_client')->id)
            ->whereNull('estruturas.pai')
            ->where('estruturas.hidden', false);

        $estruturas = $this->filterEstruturas($query, $order);
        return response()->json($estruturas);
    }

    /**
     * Get the children for a structure on lazy load.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEstruturaLazy(Request $request)
    {
        $query = Estrutura::query()
            ->withCount('children')
            ->where('estruturas.pai', $request->parent)
            ->where('hidden', false);
        $estruturas = $this->filterEstruturas($query, $request->order);

        return response()->json($estruturas);
    }


    /**
     * Processa o donwload das estruturas
     * @param Request $request
     */
    public function downloadEstrutura(Request $request)
    {
        $dataform = $request->all();
        DownloadS3Estructure::dispatch($dataform)->onQueue('default_long');
    }

    /**
     * Adiciona/remove acesso a um determinado usuário a estruturas
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function atribuirAcessoEstruturas(Request $request, $id): \Illuminate\Http\RedirectResponse
    {
        $user = User::query()->find($id);
        $this->authorize('update', $user);
        if ($request->input('estrutura')) {
            if ($user->hasPermissionTo('view estruturas')) {
                $user->revokePermissionTo('view estruturas');
            }
            if ($user->hasPermissionTo('view estruturas do cliente')) {
                $user->revokePermissionTo('view estruturas do cliente');
            }
            $estruturas = $request->input('estrutura');
            $user->estruturas()->sync($estruturas);
        } else {
            $user->estruturas()->detach();
            $user->givePermissionTo('view estruturas do cliente');
        }
        notify()->success('Estruturas atualizadas');
        return redirect()->route('usuarios.acesso', $user->id);
    }

    public function categoriasCount($id)
    {
        return Estrutura::query()
            ->withCount('children')
            ->find($id);
    }

}
