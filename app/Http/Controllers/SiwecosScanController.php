<?php

namespace App\Http\Controllers;

use App;
use App\User;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PDF;

class SiwecosScanController extends Controller {
	/**
	 * Weighting array for the individual scanners - lower value means lower impact to scoring
	 */
	const SCANNER_WEIGHTS = [
		"HEADER"   => 5,
		"DOMXSS"   => 5,
		"INFOLEAK" => 5,
		"INI_S"    => 5,
		"WS_TLS"   => 5
	];

	var $coreApi;
	var $currentDomain;

	public function __construct() {
		$this->coreApi = new CoreApiController();
	}

	public function CreateNewScan( Request $request ) {
		$userToken = $request->header( 'userToken' );
		$tokenUser = User::where( 'token', $userToken )->first();
		if ( $tokenUser instanceof User ) {
			Log::info( 'User ' . $tokenUser->email . ' requested Scan Start' );
			$response = $this->coreApi->CreateScan( $userToken, $request->domain, $request->dangerLevel );
			if ( $response instanceof RequestException ) {
				$responseText = json_decode( $response->getResponse()->getBody() );
				throw new HttpResponseException( response()->json( $responseText, $response->getCode() ) );
			}

			return $response;
		}

		return response( "User not Found", 404 );
	}

	public function BrodcastScanResult( int $id ) {

		event( new App\Events\FreeScanReady( $id ) );
	}

	public function GetScanResultById( int $id ) {
		//Validation if free scan
		$response = $this->coreApi->GetResultById( $id );
//		dd( $response );
		$response      = $this->calculateScorings( $response );
		$rawCollection = collect( $response );
		App::setLocale( 'de' );

		return response()->json( $this->translateResult( $rawCollection, 'de' ) );
	}

	/**
	 * @param int $id
	 *
	 * @return float
	 */
	public function GetTotalScore(int $id): float {
		$response = $this->coreApi->GetResultById( $id );
		$response      = $this->calculateScorings( $response );
		return $response['weightedMedia'];
	}

	public function GetScanResultRaw( Request $request ) {
		$userToken = $request->header( 'userToken' );
		$tokenUser = User::where( 'token', $userToken )->first();
		App::setLocale( 'de' );
		if ( $tokenUser instanceof User ) {
			$response = $this->coreApi->GetScanResultRaw( $userToken, $request->domain );
			if ( $response instanceof RequestException ) {
				$responseText = json_decode( $response->getResponse()->getBody() );
				throw new HttpResponseException( response()->json( $responseText, $response->getCode() ) );
			}

			return $response;
		}

		return response( "User not Found", 404 );
	}

	/**
	 * @param Request $request
	 * @param string $lang
	 *
	 * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response
	 */
	public function GetScanResult( Request $request, string $lang = 'de' ) {
		Log::info( 'GET RESULTS FOR ' . $request->get( 'domain' ) . ' LANG ' . $lang );
		$userToken = $request->header( 'userToken' );
		$tokenUser = User::where( 'token', $userToken )->first();
		App::setLocale( $lang );
		if ( $tokenUser instanceof User ) {
			$response = $this->coreApi->GetScanResultRaw( $userToken, $request->get( 'domain' ) );
			$response = $this->calculateScorings( $response );


			$rawCollection = collect( $response );

//			dd("LOREM");
			return response()->json( $this->translateResult( $rawCollection, $lang ) );
		}

		return response( "Result not found", 412 );
	}

	public function GetSimpleOutput( Request $request, string $lang = 'de' ) {
		Log::info( 'GET RESULTS FOR ' . $request->get( 'domain' ) . ' LANG ' . $lang );
		App::setLocale( $lang );
		$domain   = 'https://' . $request->get( 'domain' );
		$response = $this->coreApi->GetScanResultRawFree( $domain );
		if ( array_key_exists( 'scanStarted', $response ) ) {
			$response      = $this->calculateScorings( $response );
			$rawCollection = collect( $response );

			return response()->json( new App\Http\Resources\SimpleDomainOutput( $this->translateResult( $rawCollection, $lang ) ) );
		}
		$domain   = 'http://' . $request->get( 'domain' );
		$response = $this->coreApi->GetScanResultRawFree( $domain );
		if ( array_key_exists( 'scanStarted', $response ) ) {
			$response      = $this->calculateScorings( $response );
			$rawCollection = collect( $response );

			return response()->json( new App\Http\Resources\SimpleDomainOutput( $this->translateResult( $rawCollection, $lang ) ) );
		}

		return response( "Result not found", 412 );

	}

	/**
	 * @param int $id
	 *
	 * @return mixed
	 */
	public function generatePdf( int $id ) {
		$data = $this->generateReportData( $id );


		/** @var Pdf $pdf */
		$pdf = PDF::loadView( 'pdf.report', $data );

		return $pdf->output();

	}

	/**
	 * @param int $id
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 */
	public function generateReport( int $id ) {
		$data = $this->generateReportData( $id );

		return View( 'pdf.report', $data );
	}

	public function getDomainName(int $id){
		$response      = $this->coreApi->GetResultById( $id );
		$response      = $this->calculateScorings( $response );
		return $response['domain'];
	}

	private function generateReportData( int $id ) {
		$response      = $this->coreApi->GetResultById( $id );
		$response      = $this->calculateScorings( $response );
		$rawCollection = collect( $response );
		App::setLocale( 'de' );
		Carbon::setLocale( 'de' );
		setlocale( LC_TIME, 'German' );
		$data = $this->gaugeData($response['weightedMedia']);
		$data['data']   = $this->translateResult( $rawCollection )['scanners'];
		$data['domain'] = $response['domain'];
		$data['date']   = Carbon::parse( $response['scanFinished']['date'] )->formatLocalized( '%A %d %B %Y %H:%M:%S' );

		return $data;
	}

	public function gaugeData($score) {
		// Radius of gauge - Fixed! Scaling is done via CSS
		$radius= 50;
		// Start gauge at 180deg+45deg
		$origin= pi()*0.25;
		// Spread 100% over 270deg
		$factor= pi()*1.5/100;
		// Degrees for the percentage
		$deg= $score * $factor;
		// red part of color
		$red= floor(min((100-$score)/25,1)*255);
		// green part of color
		$green= floor(min($score/75,1)*255);
		return [
			'score'     => $score,
			'score_x'   => -cos($deg - $origin) * $radius,
			'score_y'   => -sin($deg - $origin) * $radius,
			'score_col' => sprintf("#%02x%02x%02x", $red, $green, 0 /*blue*/),
			'big_arc'   => $deg > pi() ? 1 : 0
		];
	}

	protected function translateResult( Collection $resultCollection, string $language = 'de' ) {
		Log::info(var_export($resultCollection, true));
		$this->currentDomain = $resultCollection['domain'];
		$scannerCollection   = collect( $resultCollection['scanners'] );
		$scannerCollection->transform( function ( $item, $key ) {
			$item['scanner_type'] = __( 'siwecos.SCANNER_NAME_' . $item['scanner_type'] );
//			dd($item['scanner_type']);
			if ( $item['has_error'] ) {
//				dd($error);
				$item['result'] = collect( array( $error ) );

				return $item;
			} else {
				$item['result'] = collect( $item['result'] );
				$item['result']->transform( function ( $item, $key ) {
					$namePlaceholder      = 'siwecos.' . $item['name'];
					$item['link']         = __( $namePlaceholder . '_LINK' );
					$item['description']  = $this->buildDescription( $namePlaceholder, $item['score'] );
					$item['report']       = $this->buildReport( $namePlaceholder, $item['score'] );
					$item['scoreTypeRaw'] = array_has( $item, 'scoreType' ) ? $item['scoreType'] : '';
					$item['scoreType']    = array_has( $item, 'scoreType' ) ? __( 'siwecos.SCORE_' . $item['scoreType'] ) : '';
					$item['testDetails']  = collect( $item['testDetails'] );
					$item['testDetails']->transform( function ( $item, $key ) {
						if (array_key_exists('placeholder', $item)){
							$item['report'] = __( 'siwecos.' . $item['placeholder'] );
						if ( array_key_exists( 'values', $item ) ) {
							if ( $item['values'] != null && self::isAssoc( $item['values'] ) ) {
								foreach ( $item['values'] as $key => $value ) {
									if ( is_array( $value ) ) {
										if ( is_array( $value[0] ) ) {
											$value = $value[0];
										}
										$value = implode( ',', $value );
									}
									$item['report'] = str_replace( '%' . $key . '%', htmlspecialchars($value), $item['report'] );
								}
							} else if ( $item['values'] != null ) {
								foreach ( $item['values'] as $value ) {
									if ( is_array( $value ) && array_key_exists( 'name', $value ) ) {
										$item['report'] = str_replace( '%' . $value['name'] . '%', htmlspecialchars($value['value']), $item['report'] );
									}

								}
							}
							$item['name'] = $item['report'];
						}
						}


						return $item;
					} );
					$item['name'] = __( 'siwecos.' . $item['name'] );

					return $item;
				} );
			}


			return $item;
		} );
		$resultCollection->put( 'scanners', $scannerCollection );

//		dd($resultCollection);
		return $resultCollection;
	}

	function isAssoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	protected function calculateScorings( array $results ) {
		$hasCrit    = false;
		$totalScore = 0;
		$scanCount  = 0;
		foreach ( $results['scanners'] as &$scanner ) {

			if ( array_key_exists( 'result', $scanner ) && is_array( $scanner['result'] ) && count( $scanner ) > 0 ) {
				foreach ( $scanner['result'] as &$result ) {
					if ( array_key_exists( 'scoreType', $result ) && ( $result['scoreType'] == 'hidden' || $result['scoreType'] == 'bonus' ) ) {
						continue;
					}
					if ( array_key_exists( 'scoreType', $result ) && $result['scoreType'] === 'critical' ) {
						$hasCrit = true;
					}
				}
				$totalScore       += $scanner['total_score'];
				$scanCount        += 1;
				$scanner['score'] = $scanner['total_score'];
			}


		}
		Log::info( 'Calculation: ' . $totalScore . '/' . $scanCount );
		$results['hasCrit']       = $hasCrit;
		$results['weightedMedia'] = floor($totalScore / $scanCount);

		return $results;
	}

	protected function weightedMedian( array $scanners, bool $hasCrit ) {
		$dividend = 0;
		$divisor  = 0;
		$maxScore = 100;
		$average  = 0;
		if ( $hasCrit ) {
			$maxScore = 20;
		}

		foreach ( $scanners as $value ) {
			if ( $value['scanner_type'] == 'hidden' || $value['scanner_type'] == 'bonus' ) {
				continue;
			}
			if ( array_key_exists( 'weight', $value ) ) {
				$dividend += ( $value['weight'] * $value['score'] );
				$divisor  += $value['weight'];
			}

		}
		if ( $divisor > 0 ) {
			$average = $dividend / $divisor;
			$average = $maxScore * ( $average / 100 );
		}


		return $average;
	}

	protected function buildDescription( string $testDesc, int $score ) {
		if ( $score == 100 ) {
			$testDesc = __( $testDesc . '_SUCCESS' );
			$testDesc = str_replace( '%HOST%', $this->currentDomain, $testDesc );

			return $testDesc;
		} else {
			$testDesc = __( $testDesc . '_ERROR' );
			$testDesc = str_replace( '%HOST%', $this->currentDomain, $testDesc );

			return $testDesc;
		}
	}

	protected function buildReport( string $testDesc, int $score ) {
		if ( $score == 100 ) {

		} else {
			$testDesc = __( $testDesc . '_ERROR_DESC' );
			$testDesc = str_replace( '%HOST%', $this->currentDomain, $testDesc );

			return $testDesc;
		}
	}
}
