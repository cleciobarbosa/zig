<?php
namespace App\Controllers;
use System\Controller\Controller;
use System\Post\Post;
use System\Get\Get;
use System\Session\Session;
use App\Rules\Logged;

use App\Models\Pedido;
use App\Models\Usuario;
use App\Models\Cliente;
use App\Models\ClienteEndereco;
use App\Models\Produto;
use App\Models\MeioPagamento;
use App\Models\ProdutoPedido;

use App\Repositories\VendasEmSessaoRepository;

ini_set('display_errors', 1);
ini_set('display_startup_erros', 1);
error_reporting(E_ALL);

class PedidoController extends Controller
{
	protected $post;
	protected $get;
	protected $layout;

  protected $idEmpresa;
  protected $idUsuarioLogado;
  protected $idPerfilUsuarioLogado;
  protected $vendasEmSessaoRepository;

	public function __construct()
	{
		parent::__construct();
		$this->layout = 'default';

		$this->post = new Post();
		$this->get = new Get();
    $this->idEmpresa = Session::get('idEmpresa');
    $this->idUsuarioLogado = Session::get('idUsuario');
    $this->idPerfilUsuarioLogado = session::get('idPerfil');

    $this->vendasEmSessaoRepository = new VendasEmSessaoRepository();

		$logged = new Logged();
		$logged->isValid();
	}

	public function index()
	{
    $pedido = new Pedido();
    $pedidos = $pedido->pedidos($this->idUsuarioLogado);

		$this->view('pedido/index', $this->layout, compact('pedidos'));
  }

  public function salvarPrimeiroPasso()
  {
    if ($this->post->hasPost()) {
      $pedido = new Pedido();
      $produtoPedido = new ProdutoPedido();

      # 7: Imcompleto
      $idSituacaoPedido = 7;
      $dadosPedido = (array) $this->post->only([
        'id_cliente', 'id_cliente_endereco'
      ]);

      $dadosPedido['id_vendedor'] = $this->idUsuarioLogado;

      try {
        $pedido->save($dadosPedido);
        echo json_encode(['status' => true, 'id_pedido' => $pedido->lastId()]);

      } catch(\Exception $e) {
        echo json_encode(['status' => false]);
        dd($e->getMessage());
      }
    }
  }

  public function adicionarProduto()
  {
    if ($this->post->hasPost()) {
      $dadosDoFormulrio =  $this->post->data();

      $produtoPedido = new ProdutoPedido();
      $produto = new Produto();
      $produto = $produto->find($dadosDoFormulrio->id_produto);

      $dadosPedido = [
        'id_pedido' => $dadosDoFormulrio->id_pedido,
        'id_produto' => $produto->id,
        'preco' => $produto->preco,
        'quantidade' => $dadosDoFormulrio->quantidade,
        'subtotal' => $produto->preco * $dadosDoFormulrio->quantidade
      ];

      try {
        $produtoPedido->save($dadosPedido);
        echo json_encode([
            'status' => true,
            'produto' => $produtoPedido->produtoPorIdProdutoPedido($produtoPedido->lastId())
        ]);

      } catch(\Exception $e) {
        echo json_encode(['status' => false]);
        dd($e->getMessage());
      }
    }
  }

	public function save()
	{
    if ($this->post->hasPost()) {
      $pedido = new Pedido();
      $produtoPedido = new ProdutoPedido();

      $dadosPedido = (array) $this->post->only([
        'id_vendedor', 'id_cliente', 'id_meio_pagamento',
        'id_cliente_endereco', 'valor_desconto', 'valor_frete',
        'previsao_entrega'
      ]);

      try {

      }  catch(\Exception $e) {
      echo json_encode(['status' => false]);
      dd($e->getMessage());
    }

    }
  }

	public function update()
	{
		if ($this->post->hasPost()) {
      $pedido = new Pedido();
      $produtoPedido = new ProdutoPedido();

      $dadosPedido = (array) $this->post->only([
        'id_vendedor', 'id_cliente', 'id_meio_pagamento',
        'id_cliente_endereco', 'valor_desconto', 'valor_frete',
        'previsao_entrega'
      ]);

      $dadosPedido['valor_desconto'] = formataValorMoedaParaGravacao($dadosPedido['valor_desconto']);
      $dadosPedido['valor_frete'] = formataValorMoedaParaGravacao($dadosPedido['valor_frete']);
      $dadosPedido['previsao_entrega'] = date('Y-m-d', strtotime($dadosPedido['previsao_entrega']));
      $dadosPedido['id_empresa'] = $this->idEmpresa;
      $dadosPedido['id_situacao_pedido'] = 1;

      /**
      * Calcula o valor total do pedido levendo-se em concideração
      * o valor do desconto e valor do frete
      */
      $dadosPedido['total'] = json_decode($this->vendasEmSessaoRepository->obterValorTotalDosProdutosNaMesa())->total;
      $dadosPedido['total'] = $pedido->valorTotalDoPedido($dadosPedido);

      try {
				$pedido->update($dadosPedido, $this->post->data()->id_pedido);

			} catch(\Exception $e) {
    		dd($e->getMessage());
      }

      try {
        foreach (json_decode($this->vendasEmSessaoRepository->obterProdutosDaMesa()) as $produto) {
          $produtoPedido = new ProdutoPedido();
          # Se não tiver o id do pedido na sessão, coloca
          $dados['id_pedido'] = $this->post->data()->id_pedido;
          $dados['id_produto'] = $produto->id;
          $dados['preco'] = $produto->preco;
          $dados['quantidade'] = $produto->quantidade;
          $dados['subtotal'] = $produto->total;

          # Vincula o pedido o produto selecionado
          if ($produtoPedido->seNaoExisteProdutoNoPedido($produto->id, $this->post->data()->id_pedido)) {
            $produtoPedido->save($dados);
          } else {
            $produtoPedido->updateProdutos($dados);
          }
        }
      } catch(\Exception $e) {
        echo json_encode(['status' => false]);
    		dd($e->getMessage());
      }

      echo json_encode(['status' => true]);
      $this->vendasEmSessaoRepository->limparSessao();
    }
  }

  public function modalFormulario($idPedido = false)
  {
    $pedido = false;
    $idClienteEnderecoPedido = false;
    $produtosSelecionadosNaEdicao = false;

    if ($idPedido) {
      $this->vendasEmSessaoRepository->limparSessao();

      $pedido = new Pedido();
      $pedido = $pedido->find($idPedido);

      $clienteEndereco = new ClienteEndereco();
      $idClienteEnderecoPedido = $clienteEndereco->find($pedido->id_cliente_endereco);

      $produtoPedido = new ProdutoPedido();
      $produtosSelecionadosNaEdicao = $produtoPedido->produtosPorIdPedido($pedido->id);

      foreach ($produtosSelecionadosNaEdicao as $produto) {
        $this->vendasEmSessaoRepository->colocarProdutosVindosDoBancoDeDadosNaMesa($produto);
      }
    }

    $usuario = new Usuario();
    $usuario = $usuario->find($this->idUsuarioLogado);

    $cliente = new Cliente();
    $clientes = $cliente->clientes($this->idEmpresa);

    $produto = new Produto();
    $produtos = $produto->produtos($this->idEmpresa);

    $meioPagamento = new MeioPagamento();
    $meiosPagamentos = $meioPagamento->all();

    $this->view('pedido/formulario', null,
      compact(
        'pedido',
        'usuario',
        'clientes',
        'produtos',
        'meiosPagamentos',
        'idClienteEnderecoPedido'
      ));
  }

  public function enderecoPorIdCliente($idCliente)
  {
    $clienteEndereco = new ClienteEndereco();
    echo json_encode($clienteEndereco->enderecos($idCliente));
  }

  public function produtosAdicionados()
  {
    echo $this->vendasEmSessaoRepository->obterProdutosDaMesa();
  }

  public function retirarProduto($idProduto, $idPedido = false)
  {
    $this->vendasEmSessaoRepository->retirarProdutoDaMesa($idProduto);
    if ($idPedido) {
      $produtoPedido = new ProdutoPedido();
      $produtoPedido->deletarProdutosDescartados($idProduto, $idPedido);
    }
  }

  public function obterOultimoProdutoAdicionado()
  {
    echo $this->vendasEmSessaoRepository->obterProdutosDaMesa('ultimo');
  }

  public function alterarAquantidadeDeUmProduto($idProduto, $quantidade)
	{
		$this->vendasEmSessaoRepository->alterarAquantidadeDeUmProdutoNaMesa($idProduto, $quantidade);
  }

  public function obterValorTotalDoPedido()
  {
    echo $this->vendasEmSessaoRepository->obterValorTotalDosProdutosNaMesa();
  }

  public function teste()
  {
    $produtoPedido = new ProdutoPedido();

    #$this->vendasEmSessaoRepository->limparSessao();
    //dd(json_decode($this->vendasEmSessaoRepository->obterProdutosDaMesa()));

    dd((array) $produtoPedido->produtosPorIdPedido(56));


  }
}

