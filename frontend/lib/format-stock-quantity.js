const formatStockQuantity = (quantityPieces, piecesPerCarton) => {
  // Validate inputs
  if (!Number.isInteger(quantityPieces) || quantityPieces < 0) {
    throw new Error('Quantity must be a non-negative integer');
  }
  
  if (!Number.isInteger(piecesPerCarton) || piecesPerCarton <= 0) {
    throw new Error('Carton size must be a positive integer');
  }
  
  // Calculate cartons and remaining pieces
  const cartons = Math.floor(quantityPieces / piecesPerCarton);
  const remainingPieces = quantityPieces % piecesPerCarton;
  
  // Format output based on results
  if (cartons > 0 && remainingPieces > 0) {
    return `${cartons} كرتونة و ${remainingPieces} قطعة`;
  } else if (cartons > 0) {
    return `${cartons} كرتونة`;
  } else if (remainingPieces > 0) {
    return `${remainingPieces} قطعة`;
  } else {
    return `${quantityPieces} قطعة`;
  }
};

export { formatStockQuantity };
