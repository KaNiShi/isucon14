<?php

declare(strict_types=1);

namespace IsuRide\Handlers\App;

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use IsuRide\Database\Model\PaymentToken;
use IsuRide\Database\Model\Ride;
use IsuRide\Handlers\AbstractHttpHandler;
use IsuRide\Model\AppPostRideEvaluation200Response;
use IsuRide\Model\AppPostRideEvaluationRequest;
use IsuRide\PaymentGateway\PostPayment;
use IsuRide\PaymentGateway\PostPaymentRequest;
use IsuRide\Response\ErrorResponse;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Symfony\Component\Uid\Ulid;

class PostRideEvaluatation extends AbstractHttpHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly PostPayment $postPayment
    ) {
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<string, string> $args
     * @return ResponseInterface
     * @throws Exception|\Throwable
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $rideId = $args['ride_id'];

        $req = new AppPostRideEvaluationRequest((array)$request->getParsedBody());
        if (!$req->valid()) {
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_BAD_REQUEST,
                new HttpBadRequestException(
                    request: $request,
                    message: 'evaluation must be between 1 and 5'
                )
            );
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM rides WHERE id = ?');
            $stmt->execute([$rideId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                $this->db->rollBack();
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_NOT_FOUND,
                    new Exception('ride not found')
                );
            }
            $ride = new Ride(
                id: $result['id'],
                userId: $result['user_id'],
                chairId: $result['chair_id'],
                pickupLatitude: $result['pickup_latitude'],
                pickupLongitude: $result['pickup_longitude'],
                destinationLatitude: $result['destination_latitude'],
                destinationLongitude: $result['destination_longitude'],
                evaluation: $result['evaluation'],
                createdAt: $result['created_at'],
                updatedAt: $result['updated_at']
            );
            $status = $this->getLatestRideStatus($this->db, $ride->id);
            if ($status !== 'ARRIVED') {
                $this->db->rollBack();
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_BAD_REQUEST,
                    new Exception('not arrived yet')
                );
            }
            $stmt = $this->db->prepare('UPDATE rides SET evaluation = ? WHERE id = ?');
            $stmt->execute([$req->getEvaluation(), $rideId]);
            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_NOT_FOUND,
                    new Exception('ride not found')
                );
            }
            $statusID = new Ulid();
            $stmt = $this->db->prepare(
                'INSERT INTO ride_statuses (id, ride_id, status) VALUES (?, ?, ?)'
            );
            $stmt->execute([(string)$statusID, $rideId, 'COMPLETED']);

            $stmt = $this->db->prepare('SELECT * FROM rides WHERE id = ?');
            $stmt->execute([$rideId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                $this->db->rollBack();
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_NOT_FOUND,
                    new Exception('ride not found')
                );
            }
            $ride = new Ride(
                id: $result['id'],
                userId: $result['user_id'],
                chairId: $result['chair_id'],
                pickupLatitude: $result['pickup_latitude'],
                pickupLongitude: $result['pickup_longitude'],
                destinationLatitude: $result['destination_latitude'],
                destinationLongitude: $result['destination_longitude'],
                evaluation: $result['evaluation'],
                createdAt: $result['created_at'],
                updatedAt: $result['updated_at']
            );
            $stmt = $this->db->prepare('SELECT * FROM payment_tokens WHERE user_id = ?');
            $stmt->execute([$ride->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                $this->db->rollBack();
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_NOT_FOUND,
                    new Exception('payment token not registered')
                );
            }
            $paymentToken = new PaymentToken(
                userId: $result['user_id'],
                token: $result['token'],
                createdAt: $result['created_at']
            );
            $fare = $this->calculateDiscountedFare(
                $this->db,
                $ride->userId,
                $ride,
                $ride->pickupLatitude,
                $ride->pickupLongitude,
                $ride->destinationLatitude,
                $ride->destinationLongitude
            );
            try {
                $paymentGatewayURL = apcu_entry('payment_gateway_url', function () {
                    $stmt = $this->db->prepare('SELECT value FROM settings WHERE name = \'payment_gateway_url\'');
                    $stmt->execute();
                    return $stmt->fetch(PDO::FETCH_ASSOC);
                });
                if (!$paymentGatewayURL) {
                    $this->db->rollBack();
                    apc_delete('payment_gateway_url');
                    return (new ErrorResponse())->write(
                        $response,
                        StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                        new Exception('payment gateway url not found')
                    );
                }
                $this->postPayment->execute(
                    $paymentGatewayURL['value'],
                    $paymentToken->token,
                    new PostPaymentRequest(
                        amount: $fare
                    ),
                    $ride,
                );
            } catch (Exception $e) {
                $this->db->rollBack();
                if ($e->getMessage() === 'errored upstream') {
                    return (new ErrorResponse())->write(
                        $response,
                        StatusCodeInterface::STATUS_BAD_GATEWAY,
                        $e
                    );
                }
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                    $e
                );
            }
            $this->db->commit();
            return $this->writeJson($response, new AppPostRideEvaluation200Response([
                'fare' => $fare,
                'completed_at' => $ride->updatedAtUnixMilliseconds(),
            ]));
        } catch (PDOException $e) {
            $this->db->rollBack();
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                $e
            );
        }
    }
}
