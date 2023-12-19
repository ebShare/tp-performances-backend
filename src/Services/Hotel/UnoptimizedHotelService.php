<?php

namespace App\Services\Hotel;

use App\Common\Database;
use App\Common\FilterException;
use App\Common\SingletonTrait;
use App\Entities\HotelEntity;
use App\Entities\RoomEntity;
use App\Services\Room\RoomService;
use Exception;
use PDO;
use App\Common\Timers;

/**
 * Une classe utilitaire pour récupérer les données des magasins stockés en base de données
 */
class UnoptimizedHotelService extends AbstractHotelService {
  
  use SingletonTrait;
  
  
  protected function __construct () {
    parent::__construct( new RoomService() );
  }
  
  
  /**
   * Récupère une nouvelle instance de connexion à la base de donnée
   *
   * @return PDO
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getDB () : PDO {
    $timer = Timers::getInstance();
    $timerId = $timer->startTimer('Tgetbdd');
    $pdo = Database::getInstance()->getPDO();
    $timer->endTimer('Tgetbdd', $timerId);
    return $pdo;
  }
  

  /**
   * Récupère une méta-donnée de l'instance donnée
   *
   * @param int    $userId
   * @param string $key
   *
   * @return string|null
   */
  protected function getMeta ( int $userId, string $key ) : ?string {
    $db = $this->getDB();
    $stmt = $db->prepare( "SELECT * FROM wp_usermeta" );
    $stmt->execute();
    
    $result = $stmt->fetchAll( PDO::FETCH_ASSOC );
    $output = null;
    foreach ( $result as $row ) {
      if ( $row['user_id'] === $userId && $row['meta_key'] === $key )
        $output = $row['meta_value'];
    }
    
    return $output;
  }
  
  
  /**
   * Récupère toutes les meta données de l'instance donnée
   *
   * @param HotelEntity $hotel
   *
   * @return array
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getMetas ( HotelEntity $hotel ) : array {
    $db = $this->getDB();
    $request = $db->prepare("SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = :hotel_id");
    $request->execute(['hotel_id' => $hotel->getId()]);
    $result = $request->fetchAll( PDO::FETCH_ASSOC );
    //echo '<pre>'; var_dump($result); echo '</pre>'; die();

    $ctv=[];
    foreach ($result as $row){
      $ctv[$row['meta_key']]=$row['meta_value'];
    }

    $metaDatas = [
      'address' => [
        'address_1' => $ctv['address_1'],
        'address_2' =>  $ctv['address_2'],
        'address_city' =>  $ctv['address_city'],
        'address_zip' =>  $ctv['address_zip'],
        'address_country' => $ctv['address_country'],
      ],
      'geo_lat' =>  $ctv['geo_lat'],
      'geo_lng' =>  $ctv['geo_lng'],
      'coverImage' =>  $ctv['coverImage'],
      'phone' =>  $ctv['phone'],
    ];
    return $metaDatas;
  }
  
  
  /**
   * Récupère les données liées aux évaluations des hotels (nombre d'avis et moyenne des avis)
   *
   * @param HotelEntity $hotel
   *
   * @return array{rating: int, count: int}
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getReviews ( HotelEntity $hotel ) : array {
    // Récupère tous les avis d'un hotel
    $stmt = $this->getDB()->prepare( "SELECT COUNT(wp_postmeta.meta_value)AS count, ROUND(AVG(wp_postmeta.meta_value)) AS avg FROM wp_posts
    INNER JOIN wp_postmeta ON  wp_posts.ID = wp_postmeta.post_id
    WHERE meta_key='rating' AND wp_posts.post_author = :hotelId;");
    $stmt->execute( [ 'hotelId' => $hotel->getId() ] );
    $reviews = $stmt->fetch( PDO::FETCH_ASSOC );
    //echo '<pre>'; var_dump($reviews); echo '</pre>'; die();

    $output = [
      'rating' => $reviews['avg'],
      'count' => $reviews['count'],
    ];
    
    return $output;
  }
  
  
  /**
   * Récupère les données liées à la chambre la moins chère des hotels
   *
   * @param HotelEntity $hotel
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   rooms: int | null,
   *   bathRooms: int | null,
   *   types: string[]
   * }                  $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws FilterException
   * @return RoomEntity
   */
  protected function getCheapestRoom ( HotelEntity $hotel, array $args = [] ) : RoomEntity {
    // On charge toutes les chambres de l'hôtel
    $query ="SELECT * FROM wp_posts WHERE post_author = :hotelId AND post_type = 'room'";
    
    $whereClauses = [];
    
    /**
     * On convertit les lignes en instances de chambres (au passage ça charge toutes les données).
     *
     * @var RoomEntity[] $rooms ;
     */
    
    
    // On exclut les chambres qui ne correspondent pas aux critères
    $filteredRooms = [];
    
    foreach ( $rooms as $room ) {
      if ( isset( $args['surface']['min'] ))
        $whereClauses[]='surface<= :min';
      
      if ( isset( $args['surface']['max'] ) )
        $whereClauses[]='surface>= :max';
      
      if ( isset( $args['price']['min']))
        $whereClauses[]='price<= :min';
      
      if ( isset( $args['price']['max']))
        $whereClauses[]='price>= :max';
      
      if ( isset( $args['rooms']))
        $whereClauses[]='bedrooms.count<= :rooms';
      
      if ( isset( $args['bathRooms']))
        $whereClauses[]='bathrooms.count<= :rooms';
      
      if ( isset( $args['types'] ) && ! empty( $args['types'] ) && ! in_array( $room->getType(), $args['types'] ) )
        $whereClauses[]='type= :types';
      
      $filteredRooms[] = $room;
    }
    
    // Si aucune chambre ne correspond aux critères, alors on déclenche une exception pour retirer l'hôtel des résultats finaux de la méthode list().
    if ( count( $filteredRooms ) < 1 )
      throw new FilterException( "Aucune chambre ne correspond aux critères" );
    
    
    // Trouve le prix le plus bas dans les résultats de recherche
    $cheapestRoom = null;
    foreach ( $filteredRooms as $room ) :
      if ( ! isset( $cheapestRoom ) ) {
        $cheapestRoom = $room;
        continue;
      }
      
      if ( intval( $room->getPrice() ) < intval( $cheapestRoom->getPrice() ) )
        $cheapestRoom = $room;
    endforeach;



    $stmt = $this->getDB()->prepare($query);
    $stmt->execute( [ 'hotelId' => $hotel->getId() ] );

    $rooms = array_map( function ( $row ) {
      return $this->getRoomService()->get( $row['ID'] );
    }, $stmt->fetchAll( PDO::FETCH_ASSOC ) );


    return $cheapestRoom;
  }
  
  
  /**
   * Calcule la distance entre deux coordonnées GPS
   *
   * @param $latitudeFrom
   * @param $longitudeFrom
   * @param $latitudeTo
   * @param $longitudeTo
   *
   * @return float|int
   */
  protected function computeDistance ( $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo ) : float|int {
    return ( 111.111 * rad2deg( acos( min( 1.0, cos( deg2rad( $latitudeTo ) )
          * cos( deg2rad( $latitudeFrom ) )
          * cos( deg2rad( $longitudeTo - $longitudeFrom ) )
          + sin( deg2rad( $latitudeTo ) )
          * sin( deg2rad( $latitudeFrom ) ) ) ) ) );
  }
  
  
  /**
   * Construit une ShopEntity depuis un tableau associatif de données
   *
   * @throws Exception
   */
  protected function convertEntityFromArray ( array $data, array $args ) : HotelEntity {
    $hotel = ( new HotelEntity() )
      ->setId( $data['ID'] )
      ->setName( $data['display_name'] );
    
    // Charge les données meta de l'hôtel
    $timer = Timers::getInstance();
   
    $timerId = $timer->startTimer('Tmeta');
    $metasData = $this->getMetas( $hotel );
    $timer->endTimer('Tmeta', $timerId);
    
    $hotel->setAddress( $metasData['address'] );
    $hotel->setGeoLat( $metasData['geo_lat'] );
    $hotel->setGeoLng( $metasData['geo_lng'] );
    $hotel->setImageUrl( $metasData['coverImage'] );
    $hotel->setPhone( $metasData['phone'] );
    
    // Définit la note moyenne et le nombre d'avis de l'hôtel
    $timer = Timers::getInstance();
   
    $timerId = $timer->startTimer('Treviews');
    $reviewsData = $this->getReviews( $hotel );
    $timer->endTimer('Treviews', $timerId);

    
    $hotel->setRating( $reviewsData['rating'] );
    $hotel->setRatingCount( $reviewsData['count'] );
    
    // Charge la chambre la moins chère de l'hôtel


    

    $timer = Timers::getInstance();
   
    $timerId = $timer->startTimer('Tcheapest');
    $cheapestRoom = $this->getCheapestRoom( $hotel, $args );
    $timer->endTimer('Tcheapest', $timerId);
    $hotel->setCheapestRoom($cheapestRoom);
    // Verification de la distance
    if ( isset( $args['lat'] ) && isset( $args['lng'] ) && isset( $args['distance'] ) ) {
      $hotel->setDistance( $this->computeDistance(
        floatval( $args['lat'] ),
        floatval( $args['lng'] ),
        floatval( $hotel->getGeoLat() ),
        floatval( $hotel->getGeoLng() )
      ) );
      
      
      if ( $hotel->getDistance() > $args['distance'] )
        throw new FilterException( "L'hôtel est en dehors du rayon de recherche" );
    }
    
    return $hotel;
  }
  
  
  /**
   * Retourne une liste de boutiques qui peuvent être filtrées en fonction des paramètres donnés à $args
   *
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   bedrooms: int | null,
   *   bathrooms: int | null,
   *   types: string[]
   * } $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws Exception
   * @return HotelEntity[] La liste des boutiques qui correspondent aux paramètres donnés à args
   */
  public function list ( array $args = [] ) : array {
    $db = $this->getDB();
    $stmt = $db->prepare( "SELECT * FROM wp_users" );
    $stmt->execute();
    
    $results = [];
    foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
      try {
        $results[] = $this->convertEntityFromArray( $row, $args );
      } catch ( FilterException ) {
        // Des FilterException peuvent être déclenchées pour exclure certains hotels des résultats
      }
    }
    
    
    return $results;
  }
}